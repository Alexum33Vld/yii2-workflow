<?php
namespace tests\unit\workflow\behavior;

use Yii;
use yii\codeception\TestCase;
use yii\base\InvalidConfigException;
use tests\codeception\unit\models\Item_04;
use raoul2000\workflow\base\Workflow;
use raoul2000\workflow\base\Status;
use raoul2000\workflow\base\Transition;
use raoul2000\workflow\base\StatusIdConverter;
use raoul2000\workflow\base\SimpleWorkflowBehavior;

class StatusIdConvertionTest extends TestCase
{
	use\Codeception\Specify;

	public $item;
	protected function setup()
	{
		parent::setUp();

		Yii::$app->set('workflowSource',[
			'class'=> 'raoul2000\workflow\source\php\WorkflowPhpSource',
			'namespace' => 'tests\codeception\unit\models'
		]);

		Yii::$app->set('converter',[
			'class'=> 'raoul2000\workflow\base\StatusIdConverter',
			'map' => [
				'Item_04Workflow/A' => '1',
				'Item_04Workflow/C' => '2',
				StatusIdConverter::VALUE_NULL => '55',
				'Item_04Workflow/B' => StatusIdConverter::VALUE_NULL
			]
		]);
	}

	public function testConvertionOnAttachSuccess()
	{
		$item = new Item_04();
		$item->attachBehavior('workflow',[
			'class' => SimpleWorkflowBehavior::className(),
			'statusConverter' => 'converter'
		]);
		$this->specify('on attach, initialize status and convert NULL to status ID', function() use ($item) {
			$this->assertEquals('Item_04Workflow/B', $item->getWorkflowStatus()->getId());
			$this->assertTrue($item->getWorkflow()->getId() == 'Item_04Workflow');
			$this->assertEquals(null, $item->status);
		});
	}
	public function testConvertionOnAttachFails()
	{
		$item = new Item_04();
		$this->setExpectedException('yii\base\InvalidConfigException', 'Unknown component ID: not_found_component');
		$item->attachBehavior('workflow',[
			'class' => SimpleWorkflowBehavior::className(),
			'statusConverter' => 'not_found_component'
		]);
	}
	public function testConvertionOnChangeStatus()
	{
		$item = new Item_04();
		$item->attachBehavior('workflow',[
			'class' => SimpleWorkflowBehavior::className(),
			'statusConverter' => 'converter'
			]);

		$this->specify('convertion is done on change status when setting the model attribute', function() use ($item) {
			$item->status = 1;
			verify($item->save())->true();
			$this->assertEquals('Item_04Workflow/A', $item->getWorkflowStatus()->getId());
		});

		$this->specify('convertion is done on change status when using SendToStatus()', function() use ($item) {
			$item->sendToStatus('Item_04Workflow/B');

			$this->assertEquals('Item_04Workflow/B', $item->getWorkflowStatus()->getId());
			$this->assertEquals(null, $item->status);
		});
	}

	public function testConvertionOnLeaveWorkflow()
	{
		$item = new Item_04();
		$item->attachBehavior('workflow',[
			'class' => SimpleWorkflowBehavior::className(),
			'statusConverter' => 'converter'
		]);

		$this->assertEquals(null, $item->status);
		$this->assertEquals('Item_04Workflow/B', $item->getWorkflowStatus()->getId());

		$this->specify('convertion is done when leaving workflow', function() use ($item) {
			$item->sendToStatus(null);
			expect('item to not be in a workflow',$item->getWorkflow())->equals(null);
			expect('item to not have status',$item->hasWorkflowStatus())->false();
			expect('status attribut to be converted into 55', $item->status)->equals(55);
		});
	}
}
