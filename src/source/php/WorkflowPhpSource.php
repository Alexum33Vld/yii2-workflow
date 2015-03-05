<?php
namespace raoul2000\workflow\source\php;

use Yii;
use yii\base\Object;
use yii\base\InvalidConfigException;
use yii\helpers\Inflector;
use yii\helpers\VarDumper;
use raoul2000\workflow\base\Status;
use raoul2000\workflow\base\Transition;
use raoul2000\workflow\base\Workflow;
use raoul2000\workflow\base\WorkflowException;
use raoul2000\workflow\base\IWorkflowDefinitionProvider;
use raoul2000\workflow\source\IWorkflowSource;
use yii\helpers\ArrayHelper;
use raoul2000\workflow\base\WorkflowValidationException;


/**
 * This class provides workflow items (Workflow, Status, Transitions) from
 * a PHP array workflow definition.
 */
class WorkflowPhpSource extends Object implements IWorkflowSource
{
	/**
	 *	The regular expression used to validate status and workflow Ids.
	 */
	const PATTERN_ID = '/^[a-zA-Z]+[[:alnum:]-]*$/';
	/**
	 * The separator used to create a status id by concatenating the workflow id and
	 * the status local id (e.g. post/draft).
	 */
	const SEPARATOR_STATUS_NAME = '/';
	/**
	 * Name of the array key for status list definition
	 */
	const KEY_NODES = 'status';
	/**
	 * Name of the key for transition list definition
	 */
	const KEY_EDGES = 'transition';
	/**
	 * Name of the key for metadata definition
	 */	
	const KEY_METADATA = 'metadata';
	/**
	 * @var string namespace where workflow definition class are located
	 */
	public $namespace = 'app\models';
	/**
	 * @var array list of all workflow definition indexed by workflow id
	 */
	private $_workflowDef = [];
	/**
	 * @var Workflow[] list of workflow instances indexed by workflow id
	 */
	private $_w = [];
	/**
	 * @var Status[] list status instances indexed by their id
	 */
	private $_s = [];
	/**
	 * @var Transition[] list of out-going Transition instances indexed by the start status id
	 */
	private $_t = [];
	

	/**
	 * Built-in types names used for class map configuration.
	 */
	const TYPE_STATUS = 'status';
	const TYPE_TRANSITION = 'transition';
	const TYPE_WORKFLOW = 'workflow';

	/**
	 * The class map is used to allow the use of alternate classes to implement built-in types. This way
	 * you can provide your own implementation for status, transition or workflow.
	 * The class map can be configured when this component is created but can't be modified afterwards.
	 *
	 * @var array
	 */
	private $_classMap = [
		self::TYPE_WORKFLOW   => 'raoul2000\workflow\base\Workflow',
		self::TYPE_STATUS     => 'raoul2000\workflow\base\Status',
		self::TYPE_TRANSITION => 'raoul2000\workflow\base\Transition'
	];
	/**
	 *
	 * @param array $config
	 */
	public function __construct($config = [])
	{
		if ( array_key_exists('classMap', $config)) {
			if ( is_array($config['classMap']) && count($config['classMap']) != 0) {
				$this->_classMap = array_merge($this->_classMap, $config['classMap']);
				unset($config['classMap']);

				// classmap validation

				foreach ([self::TYPE_STATUS, self::TYPE_TRANSITION, self::TYPE_WORKFLOW] as $type) {
					$className = $this->getClassMapByType($type);
					if ( empty($className)) {
						throw new InvalidConfigException("Invalid class map value : missing class for type ".$type);
					}
				}
			} else {
				throw new InvalidConfigException("Invalid property type : 'classMap' must be a non-empty array");
			}
		}
		parent::__construct($config);
	}
	/**
	 * Returns the status whose id is passed as argument.
	 * If this status was never loaded before, it is loaded now and stored for later use (lazy loading).
	 *
	 * If a $model is provided, it must be a BaseActiveRecord instance with a SimpleWorkflowBehavior attached. This model
	 * is used to complete the status ID if the one defined by the $id argument is not complete (e.g. 'draft' instead of 'post/draft').
	 *
	 * @param string $id ID of the status to get
	 * @param ActiveBaseRecord $model model instance used to resolve the status ID
	 * @return Status the status instance
	 *
	 * @see raoul2000\workflow\source\IWorkflowSource::getStatus()
	 * @see WorkflowPhpSource::evaluateWorkflowId()
	 * @see WorkflowPhpSource::parseStatusId()
	 */
	public function getStatus($id, $model = null)
	{
		list($wId, $stId) = $this->parseStatusId($id, $model);
		
		$canonicalStId = $wId . self::SEPARATOR_STATUS_NAME . $stId;
		
		if (  ! array_key_exists($canonicalStId, $this->_s) ) {
			$wDef = $this->getWorkflowDefinition($wId);
			if ( $wDef == null) {
				throw new WorkflowException('No workflow found with id ' . $wId);
			}
			if ( ! \array_key_exists($canonicalStId, $wDef[self::KEY_NODES])) {
				throw new WorkflowException('No status found with id '. $canonicalStId);
			}
			$stDef = $wDef[self::KEY_NODES][$canonicalStId] != null ? $wDef[self::KEY_NODES][$canonicalStId] : [];
			unset($stDef[self::KEY_EDGES]);
			
			$stDef['class'] = $this->getClassMapByType(self::TYPE_STATUS);
			$stDef['workflowId'] = $wId;
			$stDef['id'] = $canonicalStId;
			$stDef['label'] = (isset($stDef['label']) ? $stDef['label'] : Inflector::camel2words($stId, true));
			
			$this->_s[$canonicalStId] = Yii::createObject($stDef);	
		}
		return $this->_s[$canonicalStId];
	}
	/**
	 * Returns all out going transitions leaving the status whose id is passed as argument.
	 * This method also create instances for the initial status and all statuses that can be
	 * reached from it.
	 * 
	 * @see raoul2000\workflow\source\IWorkflowSource::getTransitions()
	 */
	public function getTransitions($statusId, $model = null)
	{

		list($wId, $lid) = $this->parseStatusId($statusId, $model);
		$statusId = $wId.self::SEPARATOR_STATUS_NAME.$lid;

		if ( ! array_key_exists($statusId, $this->_t) ) {

			$start = $this->getStatus($statusId);
			if ( $start == null) {
				throw new WorkflowException('start status not found : id = '. $statusId);
			}

			$wDef = $this->getWorkflowDefinition($wId);

			$trDef = isset($wDef[self::KEY_NODES][$start->getId()][self::KEY_EDGES])
				? $wDef[self::KEY_NODES][$start->getId()][self::KEY_EDGES]
				: null;

			$transitions = [];
			if ( $trDef != null) {
				
				foreach ($trDef as $key => $value) {
					$endStId = $key;
					$trCfg = $value;
					
					$ids = $this->parseStatusId($endStId, $model, $wId);
					$endId =  implode(self::SEPARATOR_STATUS_NAME, $ids);
					$end = $this->getStatus($endId);
					
					if ( $end == null ) {
						throw new WorkflowException('end status not found : start(id='.$statusId.') end(id='.$endStId.')');
					} else {
						$trCfg['class'] = $this->getClassMapByType(self::TYPE_TRANSITION);
						$trCfg['start'] = $start;
						$trCfg['end'  ] = $end;
						$transitions[] = Yii::createObject($trCfg);
					}					
				}
			}
			$this->_t[$statusId] = $transitions;
		}
		return $this->_t[$statusId];
	}

	/**
	 * (non-PHPdoc)
	 * @see \raoul2000\workflow\source\IWorkflowSource::getTransition()
	 */
	public function getTransition($startId, $endId, $model = null)
	{
		$tr = $this->getTransitions($startId, $model);
		if ( count($tr) > 0 ) {
			foreach ($tr as $aTransition) {
				if ($aTransition->getEndStatus()->getId() == $endId) {
					return $aTransition;
				}
			}
		}
		return null;
	}
	/**
	 * Returns the Workflow instance whose id is passed as argument.
	 *
	 * @see \raoul2000\workflow\WorkflowSource::getWorkflow()
	 * @return Workflow|null The workflow instance or NULL if no workflow could be found
	 */
	public function getWorkflow($id)
	{
		if ( ! array_key_exists($id, $this->_w) ) {

			$workflow = null;
			$def =  $this->getWorkflowDefinition($id);

			if ( $def != null ) {
				unset($def[self::KEY_NODES]);
				$def['id'] = $id;
				if ( isset($def[Workflow::PARAM_INITIAL_STATUS_ID])) {
					$ids = $this->parseStatusId($def[Workflow::PARAM_INITIAL_STATUS_ID], null, $id);
					$def[Workflow::PARAM_INITIAL_STATUS_ID] = implode(self::SEPARATOR_STATUS_NAME, $ids);
				} else {
					throw new WorkflowException('failed to load Workflow '.$id.' : missing initial status id');
				}
				$def['class'] = $this->getClassMapByType(self::TYPE_WORKFLOW);
				$workflow = Yii::createObject($def);
			}
			$this->_w[$id] = $workflow;
		}
		return $this->_w[$id];
	}

	/**
	 * Loads definition for the workflow whose id is passed as argument.
	 * The workflow Id passed as argument is used to create the class name of the object
	 * that holds the workflow definition.
	 *
	 * @param string $id
	 * @param object $model
	 * @throws WorkflowException the definition could not be loaded
	 */
	public function getWorkflowDefinition($id)
	{
		if ( ! $this->isValidWorkflowId($id)) {
			throw new WorkflowException('Invalid workflow Id : '.VarDumper::dumpAsString($id));
		}

		if ( ! isset($this->_workflowDef[$id]) ) {
			$wfClassname = $this->getClassname($id);
			try {
				$wfProvider = Yii::createObject(['class' => $wfClassname]);
			} catch ( \ReflectionException $e) {
				throw new WorkflowException('failed to load workflow definition : '.$e->getMessage());
			}
			if ( $this->isWorkflowProvider($wfProvider)) {
				$this->_workflowDef[$id] = $this->validate($id, $wfProvider->getDefinition());
				
			} else {
				throw new WorkflowException('Invalid workflow provider class : '.$wfClassname);
			}
		}
		return $this->_workflowDef[$id];
	}

	/**
	 * Returns the complete name for the Workflow Provider class used to retrieve the definition of workflow $workflowId.
	 * The class name is built by appending the workflow id to the namespace parameter set for this source component.
	 *
	 * @param string $workflowId a workflow id
	 * @return string the full qualified class name used to provide definition for the workflow
	 */
	public function getClassname($workflowId)
	{
		if ( ! $this->isValidWorkflowId($workflowId)) {
			throw new WorkflowException('Not a valid workflow Id : '.$workflowId);
		}
		return $this->namespace . '\\' . $workflowId;
	}

	/**
	 * Returns the class map array for this Workflow source instance.
	 *
	 * @param string $type
	 * @return string[]
	 */
	public function getClassMap()
	{
		return $this->_classMap;
	}
	/**
	 * Returns the class name that implement the type passed as argument.
	 * There are 3 built-in types that must have a class name :
	 *
	 * - self::TYPE_WORKFLOW
	 * - self::TYPE_STATUS
	 * - self::TYPE_TRANSITION
	 *
	 * The constructor ensure that if a class map is provided, it include class names for these 3 types. Failure to do so
	 * will result in an exception being thrown by the constructor.
	 *
	 * @param string $type Type name
	 * @return string | null the class name or NULL if no class name is found forthis type.
	 */
	public function getClassMapByType($type)
	{
		return array_key_exists($type, $this->_classMap) ? $this->_classMap[$type] : null;
	}
	/**
	 * Returns TRUE if the $object is a workflow provider.
	 * An object is a workflow provider if it implements the IWorkflowDefinitionProvider interface.
	 *
	 * @param Object $object
	 * @return boolean
	 */
	public function isWorkflowProvider($object)
	{
		return method_exists($object, 'getDefinition');
		return $object instanceof IWorkflowDefinitionProvider;
	}

	/**
	 * Parses the string $val assuming it is a status id and returns and array
	 * containing the workflow ID and status local ID.
	 *
	 * If $val does not include the workflow ID part (i.e it is not in formated like "workflowID/statusID")
	 * this method uses $model and $defaultWorkflowId to get it.
	 *
	 * @param string $val the status ID to parse
	 * @param Model|null $model a model used as workflow ID provider if needed
	 * @param string|null $defaultWorkflowId a default workflow ID value
	 * @return string[] array containing the workflow ID in its first index, and the status Local ID
	 * in the second
	 * @throws WorkflowException Exception thrown if the method was not able to parse $val.
	 * @see WorkflowPhpSource::evaluateWorkflowId()
	 */
	public function parseStatusId($val, $model = null, $defaultWorkflowId = null)
	{
		if (empty($val) || ! is_string($val)) {
			throw new WorkflowException('Not a valid status id : a non-empty string is expected  - status = '.VarDumper::dumpAsString($val));
		}

		$tokens = explode(self::SEPARATOR_STATUS_NAME, $val);
		$tokenCount = count($tokens);
		if ( $tokenCount == 1) {
			$tokens[1] = $tokens[0];
			$tokens[0] = $this->evaluateWorkflowId($model, $defaultWorkflowId);
			if ( $tokens[0] === null ) {
				throw new WorkflowException('Not a valid status id format: failed to get workflow id - status = '.VarDumper::dumpAsString($val));
			}
		} elseif ( $tokenCount != 2) {
			throw new WorkflowException('Not a valid status id format: '.VarDumper::dumpAsString($val));
		}

		if (! $this->isValidWorkflowId($tokens[0]) ) {
			throw new WorkflowException('Not a valid status id : incorrect workflow id format in '.VarDumper::dumpAsString($val));
		}elseif (! $this->isValidStatusLocalId($tokens[1]) ) {
			throw new WorkflowException('Not a valid status id : incorrect status local id format in '.VarDumper::dumpAsString($val));
		}
		return $tokens;
	}

	/**
	 * Finds and returns the workflow ID to use with the model passed as argument.
	 *
	 * If $model is not NULL, this method returns the ID of the workflow $model is currently in,
	 * or its default workflow ID if $model is not in a workflow.
	 * If $model is NULL, this method simply returns $defaultWorkflowId
	 *
	 * @param yii\db\BaseActiveRecord $model a Model instance having the SimpleWorkflow Behavior
	 * @param string $defaultWorkflowId
	 * @return NULL| string a workflow ID or NULL if no workflow ID could be found
	 */
	private function evaluateWorkflowId($model, $defaultWorkflowId)
	{
		$workflowId = null;
		if ( $model !== null && $model instanceof yii\db\BaseActiveRecord) {
			if ($model->hasWorkflowStatus() ) {
				$workflowId = $model->getWorkflowStatus()->getWorkflowId();
			} else {
				$workflowId = $model->getDefaultWorkflowId();
			}
		}elseif ( !empty($defaultWorkflowId) && is_string($defaultWorkflowId)){
			$workflowId = $defaultWorkflowId;
		}
		return $workflowId;
	}
	/**
	 * Checks if the string passed as argument can be used as a status ID.
	 *
	 * This method focuses on the status ID format and not on the fact that it actually refers
	 * to an existing status.
	 *
	 * @param string $id the status ID to test
	 * @return boolean TRUE if $id is a valid status ID, FALSE otherwise.
	 * @see WorkflowPhpSource::parseStatusId()
	 */
	public function isValidStatusId($id)
	{
		try {
			$this->parseStatusId($id);
			return true;
		} catch (WorkflowException $e) {
			return false;
		}
	}
	/**
	 * Checks if the string passed as argument can be used as a workflow ID.
	 *  
	 * A workflow ID is a string that matches self::PATTERN_ID.
	 *
	 * @param string $val
	 * @return boolean TRUE if the $val can be used as workflow id, FALSE otherwise
	 */
	public function isValidWorkflowId($val)
	{
		return is_string($val) && preg_match(self::PATTERN_ID, $val) != 0;
	}

	/**
	 * Checks if the string passed as argument can be used as a status local ID.
	 *
	 * @param string $val
	 * @return boolean
	 */
	public function isValidStatusLocalId($val)
	{
		return is_string($val) && preg_match(self::PATTERN_ID, $val) != 0;
	}

	/**
	 * Add a workflow definition array to the collection of workflow definitions handled by this source.
	 * This method can be use for instance, by a model that holds the definition of the workflow it is
	 * using.<br/>
	 * If a workflow with same id already exist in this source, it is overwritten if the last parameter
	 * is set to TRUE. Note that in this case the overwritten workflow is not available anymore.
	 *
	 * @see SimpleWorkflowBehavior::attach()
	 * @param string $workflowId
	 * @param array $definition
	 * @param boolean $overwrite When set to TRUE, the operation will fail if a workflow definition
	 * already exists for this ID. Otherwise the existing definition is overwritten.
	 * @return boolean TRUE if the workflow definition could be added, FALSE otherwise
	 */
	public function addWorkflowDefinition($workflowId, $definition, $overwrite = false)
	{
		if ( ! $this->isValidWorkflowId($workflowId)) {
			throw new WorkflowException('Not a valid workflow Id : '.$workflowId);
		}

		if ( $overwrite == false && isset($this->_workflowDef[$workflowId])) {
			return false;
		} else {
			$this->_workflowDef[$workflowId] = $this->validate($workflowId, $definition);
			unset($this->_w[$workflowId]);
			return true;
		}
	}
	/**
	 * Loads a workflow defined as a PHP Array.
	 * 
	 * @param string $wId
	 * @param array $definition
	 * @throws WorkflowValidationException
	 */
	public function validate($wId, $definition) {
		
		$normalized = [];
		if ( ! isset($definition['initialStatusId'])) {
			throw new WorkflowValidationException('Missing "initialStatusId"');
		}
		
		$pieces = $this->parseStatusId( $definition['initialStatusId'],null,$wId);
		$initialStatusId = \implode(self::SEPARATOR_STATUS_NAME, $pieces);
		
		if ( ! isset($definition[self::KEY_NODES])) {
			throw new WorkflowValidationException("No status definition found");
		}
		$normalized['initialStatusId'] = $initialStatusId;
		
		$startStatusIdIndex = [];
		$endStatusIdIndex = [];
		
		foreach($definition[self::KEY_NODES] as $key => $value) {
			$startStatusId = null;
			$startStatusDef = null;
			if ( is_string($key) ) {
				/**
				 * 'status' => ['A' => ???]
				 */				
				$startStatusId = $key;
				if( $value == null) {
					$startStatusDef = $startStatusId;	// 'status' => ['A' => null]
				}elseif (  \is_array($value)) {
					$startStatusDef = $value;			// 'status' => ['A' => [ ...] ]
				}else {
					throw new WorkflowValidationException("Wrong definition for status $startStatusId : array expected");
				}
			} elseif ( is_string($value)) {
				/**
				 * 'status' => ['A']
				 */
				$startStatusId = $value;
				$startStatusDef = $startStatusId;
			} else {
				throw new WorkflowValidationException("Wrong status definition : key = " . VarDumper::dumpAsString($key). " value = ". VarDumper::dumpAsString($value));
			}
						
			$pieces = $this->parseStatusId($startStatusId,null,$wId);
			$startStatusId = $startStatusIdIndex[] = \implode(self::SEPARATOR_STATUS_NAME, $pieces);	
			
			if ( is_array($startStatusDef) ) {
				
				if( count($startStatusDef) == 0) {
					/**
					 * empty status config array
					 * 
					 * 'A' => []
					 */		
					$normalized[self::KEY_NODES][$startStatusId] = null;
				} else {
					foreach ($startStatusDef as $startStatusKey => $startStatusValue) {
						if ( $startStatusKey == self::KEY_METADATA ) 
						{
							/**
							 * validate metadata
							 * 
							 * 'A' => [
							 * 		'metadata' => [ 'key' => 'value']
							 * ]
							 */
							
							if ( \is_array($startStatusDef[self::KEY_METADATA])) {
								if (! ArrayHelper::isAssociative($startStatusDef[self::KEY_METADATA])) {
									throw new WorkflowValidationException("Invalid metadata definition for status $startStatusId : associative array expected");
								}
							} else {
								throw new WorkflowValidationException("Invalid metadata definition for status $startStatusId : array expected");
							}
							$normalized[self::KEY_NODES][$startStatusId][self::KEY_METADATA] = $startStatusDef[self::KEY_METADATA];
						} 
						elseif ( $startStatusKey == 'transition') 
						{				
							$transitionDefinition = $startStatusDef['transition'];
							if ( \is_string($transitionDefinition)) {
								/**
								 *  'A' => [
								 *   	'transition' => 'A, B, WID/C'
								 *   ]
								 */							
								$ids = array_map('trim', explode(',', $transitionDefinition));
								foreach ($ids as $id) {
									$pieces = $this->parseStatusId($id,null,$wId);
									$canEndStId = \implode(self::SEPARATOR_STATUS_NAME, $pieces);
									$endStatusIdIndex[] = $canEndStId;
									$normalized[self::KEY_NODES][$startStatusId]['transition'][$canEndStId] = [];
								}
							} elseif ( \is_array($transitionDefinition)) {
								/**
								 *  'transition' => [ ...]
								 */							
								foreach( $transitionDefinition as $tkey => $tvalue) {
									if ( \is_string($tkey)) {
										/**
										 * 'transition' => [ 'A' => [] ]
										 */									
										$endStatusId = $tkey;
										if ( ! \is_array($tvalue)) {
											throw new WorkflowValidationException("Wrong definition for between $startStatusId and $endStatusId : array expected");
										}		
										$transDef = $tvalue;
									} elseif ( \is_string($tvalue)){
										/**
										 * 'transition' => [ 'A' ]
										 */									
										$endStatusId = $tvalue;
										$transDef = null;
									} else {
										throw new WorkflowValidationException("Wrong transition definition for status $startStatusId : key = " 
											. VarDumper::dumpAsString($tkey). " value = ". VarDumper::dumpAsString($tkey));
									}
									
									$pieces = $this->parseStatusId($endStatusId,null,$wId);
									$canEndStId = \implode(self::SEPARATOR_STATUS_NAME, $pieces);	
									$endStatusIdIndex[] = $canEndStId;
									
									if ( $transDef != null) {
										$normalized[self::KEY_NODES][$startStatusId]['transition'][$canEndStId] = $transDef;
									}else {
										$normalized[self::KEY_NODES][$startStatusId]['transition'][$canEndStId] = [];
									}
								}
							} else {
								throw new WorkflowValidationException("Invalid transition definition format for status $startStatusId : string or array expected");
							}
						} 
						elseif ( \is_string($startStatusKey)) {
							$normalized[self::KEY_NODES][$startStatusId][$startStatusKey] = $startStatusValue;
						} 
					}
				}
			} else { //$startStatusDef is not array
				/**
				 * Node IDS must be canonical and array keys
				 * 'status' => [
				 * 		'A'
				 * ]
				 *  turned into
				 *  
				 * 'status' => [
				 * 		'WID/A' => null
				 * ]

				 */
				$normalized[self::KEY_NODES][$startStatusId] = null;
			}
		}
		
		if ( ! \in_array($initialStatusId, $startStatusIdIndex)) {
			throw new WorkflowValidationException("Initial status not defined : $initialStatusId");
		}
		
		// detect not defined statuses 
		
		$missingStatusIdSuspects = \array_diff($endStatusIdIndex, $startStatusIdIndex);
		if ( count($missingStatusIdSuspects) != 0) {
			$missingStatusId = [];
			foreach ($missingStatusIdSuspects as $id) {
				list($thisWid, $thisSid) = $this->parseStatusId($id,null,$wId);
				if ($thisWid == $wId) {
					$missingStatusId[] = $id; // refering to the same workflow, this Id is not defined
				}
			}
			if ( count($missingStatusId) != 0) {
				throw new WorkflowValidationException("One or more end status are not defined : ".VarDumper::dumpAsString($missingStatusId));
			}
		}
		return $normalized;
	}
}
