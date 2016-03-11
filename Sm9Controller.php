<?php
/* 
 */
class Sm9Controller extends Zend_Controller_Action
{
	private $db;
	
	const DATACONCEPT_WORKORDER = 'WORK ORDER';
	const DATACONCEPT_CHANGE    = 'CHANGE';
	const DATACONCEPT_INCIDENT  = 'INCIDENT';

	public function init() {
		$this->db = Zend_Registry::get('db');
		$this->_helper->layout()->disableLayout();
	}

	private function formatData($data) {
		$newData = array();
		foreach ($data as $item) {
			$newData[$item['name']] = $item['value'];
		}
		return $newData;
	}
	
	public function testattachmentAction() {
		$this->_helper->viewRenderer->setNoRender(true);
		$sm9 = new Application_Model_SM9();
		echo $sm9->testAttachment();
	}
	
	public function addattachmentAction() {
		$this->_helper->viewRenderer->setNoRender(true);
		$request = $this->getRequest();
		$id = $request->getParam('id');
		$data = array(
			"id" => $id
		);
		$sm9 = new Application_Model_SM9();
		echo $sm9->addAttachment($data);
	}
	
	public function savetemplateAction() {
		$request = $this->getRequest();
		$templateId = $request->getParam('template_id');
		$theForm = $request->getParam('form');
		$this->view->taskId = $request->getParam('task_id');

		// get params, send to model
		$params = $request->getParams();
		$sm9 = new Application_Model_SM9();
		$ret = $sm9->saveTemplate($params, $templateId);
		
		// store return message and template id
		$this->view->message = $ret['message'];
		$request->setParam("template_id", $ret['template_id']);
		
		// AFTER...
		$this->_forward($theForm);
	}
	
	public function saveticketAction() {
		$this->_helper->viewRenderer->setNoRender(true);

		// get params, send to model
		$request = $this->getRequest();
		$params = $request->getParams();
		$sm9 = new Application_Model_SM9();
		$ret = $sm9->saveTicket($params);
		
		// AFTER...
		$this->render('saved');
	}
	
	public function submitticketAction() {
		$this->_helper->viewRenderer->setNoRender(true);
		$request = $this->getRequest();
		$taskId = $request->getParam('id',null);
		
		if (!empty($taskId)) {
			$sm9 = new Application_Model_SM9();
			$sm9Ticket = $sm9->getTicketByTask($taskId);
			if ($sm9Ticket && empty($sm9Ticket['data']['id'])) {
				$x = $sm9->createTicket($taskId);
				if (gettype($x) === 'array') {
					echo $x['message'];
				} else {
					echo $x;
				}
			} else {
				echo "SM9 TICKET FOR TASK $taskId ALREADY CREATED (ID = " . $sm9Ticket['data']['id'] . ")";
			}
		}
	}
	
	public function submitticket2Action() {
		// get params, send to model
		$request = $this->getRequest();
		$taskId = $request->getParam('task_id',null);
		$srId = $request->getParam('sr_id',null);
		$params = $request->getParams();
		$sm9 = new Application_Model_SM9();
		$ret = $sm9->saveTicket($params);
		
		// submit to SM9
		if (!empty($taskId)) {
			$sm9Ticket = $sm9->getTicketByTask($taskId);
			if ($sm9Ticket && empty($sm9Ticket['data']['id'])) {
				$x = $sm9->createTicket($taskId); // NEED TO ALLOW MULTIPLE TICKETS PER TASK/DATACONCEPT
			}
		}
		
		// redirect
		$url = SITE_URL . "home/ticketinfo/id/" . $srId;
		if ($sm9Ticket['data_concept'] === 'CHANGE') {
			$url .= '/anchor/sm9_change_' . $srId . '_' . $taskId;
		} else if ($sm9Ticket['data_concept'] === 'WORK ORDER') {
			$url .= '/anchor/sm9_wo_' . $srId . '_' . $taskId;
		}
		$this->_redirect($url);
	}
	
	public function changeformAction() {
		$this->populateForm(self::DATACONCEPT_CHANGE);
	}
	
	public function workorderformAction() {
		$this->populateForm(self::DATACONCEPT_WORKORDER);
	}
	
	public function incidentformAction() {
		$this->populateForm(self::DATACONCEPT_INCIDENT);
	}
	
	private function populateForm($dataConcept) {
		$request = $this->getRequest();
		$templateId = $request->getParam("template_id");
		$this->view->srId = $request->getParam('sr_id');
		if ($request->getParam('service_request_actions_id')) {
			$this->view->taskId = $request->getParam('service_request_actions_id');
		} else {
			$this->view->taskId = $request->getParam('task_id');
		}
		
		// GET ALL TEMPLATES
		$model = new Application_Model_SM9();
		$this->view->all_templates = $model->getTemplates($dataConcept);
			
		if ($templateId) {
			// GET TEMPLATE INFO
			$this->view->template = $model->getTemplate($templateId);
			
			// GET TEMPLATE DATA
			$this->view->data = $model->getTemplateData($templateId);
		}
	}
	
	/********** THE FUNCTIONS BELOW HERE ARE FOR TESTING PURPOSES ONLY ***********/
	
	/******** WORK ORDERS ********/
	public function searchworkordersAction() {
		$request = $this->getRequest();
 		// if isPost, grab form elements and stuff into array, send to model, display results
		if ($request->isPost()) {
			// build data array
			$data['Field'] = $_POST['Field'];
			$data['Operator'] = $_POST['Operator'];
			$data['Value'] = $_POST['Value'];
			$data['GroupBy'] = $_POST['GroupBy'];
			
			// model
			$sm9_model = new Application_Model_SM9();
			$this->view->results = $sm9_model->searchWorkOrders($data);
		}
	}
	
	public function createworkorderAction() {
		$request = $this->getRequest();
 		// if isPost, grab form elements and stuff into array, send to model, display results
		if ($request->isPost()) {
			// build data array
			/*
			$data['Field'] = $_POST['Field'];
			*/
			// model
			$sm9_model = new Application_Model_SM9();
			$this->view->results = $sm9_model->createWorkOrder($data);
		}
	}
	
	public function updateworkorderAction() {
		$request = $this->getRequest();
 		// if isPost, grab form elements and stuff into array, send to model, display results
		if ($request->isPost()) {
			// build data array
			$id = "E-O00073751-001";
			$data['WorkOrderId'] = $_POST['WorkOrderId'];
			$data['Name'] = $_POST['Name'];
			$data['ServiceProvider'] = $_POST['ServiceProvider'];
			$data['Location'] = $_POST['Location'];
			$data['PartNumber'] = $_POST['PartNumber'];
			
			// model
			$sm9_model = new Application_Model_SM9();
			$this->view->results = $sm9_model->updateWorkOrder($id, $data);
		}
	}
	
	public function closeworkorderAction() {
		$request = $this->getRequest();
 		// if isPost, grab form elements and stuff into array, send to model, display results
		if ($request->isPost()) {
			// build data array
			$data = array(
				"id" => "E-O00075532-001",
				"customer" => "HP ECS EIT 2"
			);
			$sm9_model = new Application_Model_SM9();
			$this->view->results = $sm9_model->closeWorkOrder($data);
		}
	}
	
	/******** CHANGE REQUESTS ********/
	public function searchchangesAction() {
		$request = $this->getRequest();
 		// if isPost, grab form elements and stuff into array, send to model, display results
		if ($request->isPost()) {
			// build data array
			$data['Field'] = $_POST['Field'];
			$data['Operator'] = $_POST['Operator'];
			$data['Value'] = $_POST['Value'];
			$data['GroupBy'] = $_POST['GroupBy'];
			
			// model
			$sm9_model = new Application_Model_SM9();
			$this->view->results = $sm9_model->searchChanges($data);
		}
	}
	
	public function createchangeAction() {
		$this->_helper->viewRenderer->setNoRender(true);
		$sm9_model = new Application_Model_SM9();
		echo $sm9_model->createTicket('8536');
	}
	
	public function updatechangeAction() {
		$request = $this->getRequest();
 		// if isPost, grab form elements and stuff into array, send to model, display results
		if ($request->isPost()) {
			$id = "E-C00129915";
			$sm9_model = new Application_Model_SM9();
			$this->view->results = $sm9_model->updateChange($id, $data);
		}
	}
	
	public function closechangeAction() {
		$request = $this->getRequest();
 		// if isPost, grab form elements and stuff into array, send to model, display results
		if ($request->isPost()) {
			$id = "E-C00129915";
			$sm9_model = new Application_Model_SM9();
			$this->view->results = $sm9_model->closeChange($id);
		}
	}
	
	/******** INCIDENTS ********/
	public function searchincidentsAction() {
		$request = $this->getRequest();
 		// if isPost, grab form elements and stuff into array, send to model, display results
		if ($request->isPost()) {
			// build data array
			$data['Field'] = $_POST['Field'];
			$data['Operator'] = $_POST['Operator'];
			$data['Value'] = $_POST['Value'];
			$data['GroupBy'] = $_POST['GroupBy'];
			$data['customer'] = 'MSCUST-A';
			
			// model
			$sm9_model = new Application_Model_SM9();
			$this->view->results = $sm9_model->searchIncidents($data);
		}
	}
	
	public function createincidentAction() {
		$request = $this->getRequest();
 		// if isPost, grab form elements and stuff into array, send to model, display results
		if ($request->isPost()) {
			// model
			$sm9_model = new Application_Model_SM9();
			$this->view->results = $sm9_model->createIncident($data);
		}
	}
	
	public function updateincidentAction() {
		$request = $this->getRequest();
 		// if isPost, grab form elements and stuff into array, send to model, display results
		if ($request->isPost()) {
			$id = "E-IM003112081";
			$sm9_model = new Application_Model_SM9();
			$this->view->results = $sm9_model->updateIncident($id, $data);
		}
	}
	
	public function closeincidentAction() {
		$request = $this->getRequest();
 		// if isPost, grab form elements and stuff into array, send to model, display results
		if ($request->isPost()) {
			$data = array(
				"id" => "E-IM000652613",
				"customer" => "HP ECS EIT 2"
			);
			$sm9_model = new Application_Model_SM9();
			$this->view->results = $sm9_model->closeIncident($data);
		}
	}
	
	/** test only, delete later **/
	public function getchangeticketAction() {
		$this->_helper->viewRenderer->setNoRender(true);
		$request = $this->getRequest();
		$sm9_model = new Application_Model_SM9();
		echo $sm9_model->getChangeTicketById("E-C00153448");
	}
}
