<?php
require_once 'RestController.php';

/**
 * Policy Planner API Integration
 * 
 * General Info:
 * 
 * FireMon has a full set of REST APIs to interact with all phases of the system.  
 * For the purpose of the NetSRM integration, there are some specific APIs that will be explained below to perform 
 * the creation of a new ticket and the retrieval of the current status of a ticket. 
 * 
 * There is also a more comprehensive API document located in your environment at the following URL:
 * https://<servername>/firemon/restapi/index.html
 */
class FiremonController extends Zend_Controller_Action
{
	private $_db;
	private $firstOpt = '<option selected="selected" value="">Please Select...</option>';

	public function init()
	{
		$this->_helper->layout->setLayout('empty');
		$this->_db = Zend_Registry::get('db');
	}

	private function getCurl($url, $method='GET') {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);		
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($ch, CURLOPT_HEADER, false);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
		curl_setopt($ch, CURLOPT_USERPWD, "netsrm:n3tS4m");
		curl_setopt($ch, CURLOPT_SSLVERSION, 1);
		return $ch;
	}
	
	public function testattachmentAction() {
		$this->_helper->layout()->disableLayout();
		$this->_helper->viewRenderer->setNoRender(true);
		$name = false;
		$data = false;
		$select = $this->_db->select()
			->from("SERVICE_REQUEST_ACTIONS_ATTACHMENTS", array('name','data'))
			//->where("service_request_action_attachments_id = ?", array('1287'))
			->where("service_request_action_attachments_id = 1287")
		;
		$stmt = $this->_db->prepare($select);
		$stmt->execute();
		$stmt->bindColumn(1, $name);
		$stmt->bindColumn(2, $data, PDO::PARAM_LOB);
		$attachment = $stmt->fetch();
		
		$fm = new Application_Model_Firemon();
		$x = $fm->addAttachment('https://138.35.217.240', '73556', array("name" => $name, "data" => $data));
		echo 'Done, $msg = ' . $x;
	}
	
	public function getfiremonticketAction() {
		$this->_helper->layout()->disableLayout();
		$this->_helper->viewRenderer->setNoRender(true);
		$request = $this->getRequest();
		$taskId = $request->getParam('id',null);
		
		$ticket = array();
		
		$fm = new Application_Model_Firemon();
		$ticket['fm_info'] = $fm->getTicket($taskId);
		$ticket['fm_requirements'] = $fm->getRequirements($taskId);
		$ticket['fm_activities'] = $fm->getActivities($taskId);
		$ticket['fm_currentTasks'] = $fm->getCurrentTasks($taskId);
		$ticket['fm_tasks'] = $fm->getTasks($taskId);
		
		// SM9 CHANGE
		$id = $ticket['fm_info']['sm9_change'];
		if (!empty($id)) {
			$sm9 = new Application_Model_SM9();
			$ticket['fm_change'] = $sm9->getChangeTicketById($id);
		}

		$this->_response->setHttpResponseCode(200);
		$this->_helper->json->sendJson($ticket);
		
		//echo json_encode($ticket);
	}
	
	/**
	 * Retrieve Domain List:
	 * You will need to pass the Id of the Domain that you want to use in other calls, such as retrieving a list of devices. 
	 * For any environment that is not setup as a multi-domain environment, the Id that will be used is “2”. 
	 * For any environment that is setup as multi-domain, the domain Id that you use will need to be retrieved using 
	 * the API call below. The values to keep track of in this call are the “id” and the “name” of the domain. 
	 * The “id” is the value that will be used on future API calls. 
	 * 
	 * URL:	https://<servername>/firemon/api/1.0/domains
	 * HTTP METHOD: GET
	 */
	public function retrievedomainlistAction() {
		$this->_helper->layout()->disableLayout();
		$this->_helper->viewRenderer->setNoRender(true);
		
		// GET DOMAINS
		$ch = $this->getCurl('https://138.35.217.240/firemon/api/1.0/domains');
		$domains = json_decode(curl_exec($ch));
		if (!empty($domains) && !empty($domains->results) && count($domains->results) > 0) {
			echo "<html><head><title>Retrieve Domain List</title></head><body>";
			$del = $this->_db->delete('FIREMON_DOMAINS');
			$del = $this->_db->delete('FIREMON_DEVICEGROUPS');
			$del = $this->_db->delete('FIREMON_DEVICEGROUPS_DEVICES');
			$del = $this->_db->delete('FIREMON_DEVICES');
			foreach ($domains->results as $domain) {
				$data = array(
					'id' => $domain->id,
					'name' => $domain->name,
					'class' => $domain->{'@class'},
					'customer_id' => $domain->customerId,
					'description' => $domain->description
				);
				$this->_db->insert('FIREMON_DOMAINS', $data);
				echo "DOMAIN ID = " . $domain->id . ", NAME = " . $domain->name . "<br/>";
				
				// GET DEVICE GROUPS
				$ch = $this->getCurl('https://138.35.217.240/firemon/api/1.0/devicegroups?domainId=' . $domain->id);
				$device_groups = json_decode(curl_exec($ch));
				if (!empty($device_groups) && !empty($device_groups->results) && count($device_groups->results) > 0) {
					foreach ($device_groups->results as $device_group) {
						$data = array(
							'id' => $device_group->id,
							'domain_id' => $device_group->domainId,
							'key' => $device_group->key,
							'name' => $device_group->name,
							'parent_id' => $device_group->parentId,
							'customer_id' => $device_group->customerId,
							'description' => $device_group->description
						);
						$this->_db->insert('FIREMON_DEVICEGROUPS', $data);
						echo "&nbsp;&nbsp;&nbsp;&nbsp;DEVICE GROUP ID = " . $device_group->id . ", NAME = " . $device_group->name . "<br/>";
						
						// GET DEVICES
						$ch = $this->getCurl('https://138.35.217.240/firemon/api/1.0/devicegroups/' . $device_group->id . '/devices');
						$devices = json_decode(curl_exec($ch));
						if (!empty($devices) && !empty($devices->results) && count($devices->results) > 0) {
							foreach ($devices->results as $device) {
								$data = array(
									'id' => $device->id,
									'domain_id' => $device->domainId,
									'name' => $device->name,
									'ip_address' => $device->ipAddress,
									'description' => $device->description,
									'data_collector_id' => $device->dataCollectorId,
									'product_version_key' => $device->productVersionKey,
									'current_configuration_set_id' => $device->currentConfigurationSetId,
									'result_of_merge' => $device->resultOfMerge,
									'central_syslog_Server_id' => $device->centralSyslogServerId,
									'type' => $device->type,
									'last_change_user_name' => $device->lastChangeUserName,
									'last_change_date_time' => $device->lastChangeDateTime,
									'is_licensed' => $device->isLicensed
								);
								$this->_db->insert('FIREMON_DEVICES', $data);
								echo "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;DEVICE ID = " . $device->id . ", NAME = " . $device->name;
								
								// MAP DEVICE TO DEVICE GROUPS
								$data = array(
									'device_id' => $device->id,
									'devicegroup_id' => $device_group->id
								);
								$this->_db->insert('FIREMON_DEVICEGROUPS_DEVICES', $data);
								echo " <span style='color:green'><i>(mapped to device group " . $device_group->id . ")</i></span><br/>";
							}
						}
					}
				}
			}
			echo "</body></html>";
		} else {
			echo "<html><head><title>Retrieve Domain List</title></head><body>ERROR FETCHING DOMAINS</body></html>";
			// DO SOME ERROR HANDLING
		}
		curl_close($ch);
	}

	/**
	 * Retrieve Device Groups:
	 * This API allows for device groups to be retrieved from FireMon by Domain. 
	 * The API has pagination capability, since there could be many device groups returned. 
	 * The parameters “start” and “count” are used to indicate what record of the data set (what page) 
	 * and how many records to return.
	 * 
	 * URL:	https://<servername>/firemon/api/1.0/devicegroups
	 * HTTP METHOD: GET
	 * Parameters: start, count, domainId
	 */
	public function retrievedevicegroupsAction() {
		$this->_helper->layout()->disableLayout();
		$this->_helper->viewRenderer->setNoRender(true);
		$request = $this->getRequest();
		$id = $request->getParam('id',null);
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");		
		curl_setopt($ch, CURLOPT_URL, 'https://138.35.217.240/firemon/api/1.0/devicegroups?domainId=' . $id);
		//curl_setopt($ch, CURLOPT_URL, 'https://138.35.217.240/firemon/api/1.0/devicegroups?domainId=' . $id . '&start=1&count=3');
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($ch, CURLOPT_HEADER, false);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_HTTPAUTH,CURLAUTH_BASIC);
		curl_setopt($ch, CURLOPT_USERPWD,"netsrm:n3tS4m");
		$response = json_decode(curl_exec($ch));
		curl_close($ch);
		echo "<html><body><head><title>Retrieve Device Groups</title></head>" . var_dump($response) . "</body></html>";
	}
	
	/**
	 * Retrieve Devices:
	 * This API allows for devices to be retrieved from FireMon by device group. 
	 * The API has pagination capability, since there could be many devices returned. 
	 * The parameters “start” and “count” are used to indicate what record of the data set (what page) 
	 * and how many records to return. The “id” of the device group that you want to retrieve a list 
	 * of devices for should be passed as a url parameter. 
	 * 
	 * URL:	https://<servername>/firemon/api/1.0/devicegroups/{id}/devices
	 * HTTP METHOD: GET
	 * Parameters: start, count
	 */
	public function retrievedevicesAction() {
		$this->_helper->layout()->disableLayout();
		$this->_helper->viewRenderer->setNoRender(true);
		$request = $this->getRequest();
		$id = $request->getParam('id',null);
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");		
		curl_setopt($ch, CURLOPT_URL, 'https://138.35.217.240/firemon/api/1.0/devicegroups/' . $id . '/devices?start=1&count=4');
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($ch, CURLOPT_HEADER, false);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_HTTPAUTH,CURLAUTH_BASIC);
		curl_setopt($ch, CURLOPT_USERPWD,"netsrm:n3tS4m");
		$response = json_decode(curl_exec($ch));
		curl_close($ch);
		echo "<html><body><head><title>Retrieve Devices</title></head>" . var_dump($response) . "</body></html>";
	}
	
	/**
	 * Retrieve Latest Workflow:
	 * Most of the API calls around workflow require several workflow variables to be used within the request. 
	 * These variables can change when a new workflow is deployed to the FireMon system. 
	 * The variables that are needed need to be retrieved before performing any subsequent calls, 
	 * just to make sure that you have the latest values. 
	 * The two values that are needed for subsequent calls are the “processDefinitionId” and “processDefinitionKey” values. 
	 * 
	 * You will need to pass the Id of the Domain that you want to use for the workflow. 
	 * For any environment that is not setup as a multi-domain environment, the Id that will be used is “2”. 
	 * For any environment that is setup as multi-domain, the domain Id that you use will need to be retrieved 
	 * using a separate API call. In this document we’ll assume a non multi-domain environment.
	 * 
	 * URL:	https://<servername>/firemon/api/1.0/workflows/current?domainId=2
	 * HTTP METHOD: GET
	 */
	public function retrievelatestworkflowAction() {
		$this->_helper->layout()->disableLayout();
		$this->_helper->viewRenderer->setNoRender(true);
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");		
		curl_setopt($ch, CURLOPT_URL, 'https://138.35.217.240/firemon/api/1.0/workflows/current?domainId=2');
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($ch, CURLOPT_HEADER, false);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_HTTPAUTH,CURLAUTH_BASIC);
		curl_setopt($ch, CURLOPT_USERPWD,"netsrm:n3tS4m");
		$response = json_decode(curl_exec($ch));
		curl_close($ch);
		echo "<html><body><head><title>Retrieve Latest Workflow</title></head>" . var_dump($response) . "</body></html>";
	}
	
	/**
	 * Create Ticket:
	 * This API is used to create a new ticket within Policy Planner. The fields in the sample below are 
	 * all the required fields. The entire list of fields that can be supplied with this API are listing 
	 * in a separate Excel spreadsheet. A sample response is also provided. When this API request is made, 
	 * the field “processInstanceId” within the response JSON is what should be stored and used for any 
	 * subsequent API calls. This would be the responsibility of the NetSRM product to store this value 
	 * with the service request ticket within NetSRM. In addition to this value, it would most likely make 
	 * sense to store the “businessKey” value from the response JSON. This value is the displayed ticket 
	 * name within Policy Planner. So from a NetSRM standpoint, if something needed to be displayed to a 
	 * user to identify the Policy Planner ticket, this field would be the right value.
	 * 
	 * URL:	https://<servername>/firemon/api/1.0/workflows/process-instance/
	 * HTTP Method: POST
	 */
	public function createticketAction() {
		$this->_helper->layout()->disableLayout();
		$this->_helper->viewRenderer->setNoRender(true);
		$post = json_encode(
			array(
				// REQUIRED
				//"processDefinitionId" => "PolicyPlanner-2:40:30107",
				"processDefinitionKey" => "PolicyPlanner-2",
  			"billingCodeCostCenter" => "Some Cost Center 7",
				"firewallHostName" => "fwFIREWALLHOSTNAME7",
				"impact" => "NON_IMPACTING", // "NON_IMPACTING","NON_IMPACTING_WITH_TIME","POTENTIALLY_IMPACTING"
				"requesterEmail" => "bob.weisend@hp.com",
				"requesterName" => "Bob Weisend",
				//"requirement.destination.0" => "any",
				//"requirement.service.0" => "any",
				//"requirement.source.0" => "any",
				"retryCounter" => 1,
				"startNotifications" => "a_create", // "a_create"
				"summary" => "Bob Is Testing The Create Ticket API 7",
				"ticketType" => "BREAKFIX", // "NONE", "FIREWALL","BREAKFIX","MISC"
				//"_applicationUrl"	=> "https://138.35.217.240/policyplanner",

				// OPTIONAL
				"carbonCopy" => "test12345678899@hp.com",
				"changeCtrl" => "false", // "true","false"
				"changeCtrlFreeze" => "false", // "true","false"
				"dueDate" => "08/14/2014", // MM/dd/yyyy
				"priority" => "NORMAL", // "NORMAL", "URGENT"
				//"requirement.action.0" => "any",
				//"requirement.expireDate.0" => "12/31/2014",
				//"requirement.reviewDate.0" => "11/30/2014"
			)
		);

		//$post = '{"processDefinitionKey":"PolicyPlanner-2","billingCodeCostCenter":"USA7075086 \/ 0000330297 (CIGNA)\/COMPASSWBS","firewallHostName":"ukbilsocfws_cluster","impact":"NON_IMPACTING","requesterEmail":"bob.weisend@hp.com","requesterName":"Bob Weisend","retryCounter":1,"startNotifications":"d_none","summary":"SUMMARY","ticketType":"BREAKFIX","businessOwner":"BUSINESSOWNER","businessUnit":"BUSINESSUNIT","carbonCopy":"justin.bieber@rehab.com","changeCtrl":null,"changeCtrlFreeze":null,"customer":"CIGNA","designRequired":"False","dueDate":"04\/23\/2014","dueTimeEnd":"4:00 PM","dueTimeStart":"9:00 AM","externalTicketId":"1664","justification":"JUSTIFICATION","notes":"NOTES","priority":"NORMAL","timezone":"f"}';
		
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");		
		//curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
		curl_setopt($ch, CURLOPT_URL, 'https://138.35.217.240/firemon/api/1.0/workflows/process-instance/');
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));		
		//curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json','Content-Length: ' . strlen($post)));		
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($ch, CURLOPT_HEADER, false);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
		curl_setopt($ch, CURLOPT_USERPWD, "netsrm:n3tS4m");
		//$response = json_decode(curl_exec($ch));
		$response = curl_exec($ch);
		curl_close($ch);
		echo "<html><head><title>Create Ticket</title></head><body>" . var_dump($response) . "</body></html>";
	}
	
	/**
	 * Retrieve Ticket Status:
	 * This API can be used to retrieve the current status of a ticket. 
	 * The parameter in the URL that is used to lookup a ticket is the “processInstanceId”. 
	 * This should be the value that was returned when a ticket was first created. 
	 * The response for this API has a lot of information. 
	 * Indicated below are the key fields to look for to get the status (current task) and assignee. 
	 * Other values can be captured and displayed as well.  
	 * 
	 * URL:	https://<servername>/firemon/api/1.0/workflows/process-instance-details/<processInstanceId>
	 * HTTP Method: GET
	 */
	public function retrieveticketstatusAction() {
		$this->_helper->layout()->disableLayout();
		$this->_helper->viewRenderer->setNoRender(true);
		$request = $this->getRequest();
		$id = $request->getParam('id',null);
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");		
		curl_setopt($ch, CURLOPT_URL, 'https://138.35.217.240/firemon/api/1.0/workflows/process-instance-details/' . $id);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($ch, CURLOPT_HEADER, false);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_HTTPAUTH,CURLAUTH_BASIC);
		curl_setopt($ch, CURLOPT_USERPWD,"netsrm:n3tS4m");
		$response = json_decode(curl_exec($ch));
		curl_close($ch);
		echo "<html><head><title>Retrieve Ticket Status</title></head><body>" . var_dump($response) . "</body></html>";
	}

	public function submitticketAction() {
		$this->_helper->layout()->disableLayout();
		$this->_helper->viewRenderer->setNoRender(true);
		$request = $this->getRequest();
		$taskId = $request->getParam('id',null);
		
		if (!empty($taskId)) {
			$fm = new Application_Model_Firemon();
			$ft = $fm->getTicket($taskId);
			if ($ft && $ft['status'] !== 'submitted') {
				echo json_encode($fm->createFiremonTicket($taskId));
			}
		}
	}
	
	public function serviceformAction() {
		$this->_helper->layout()->disableLayout();
		$request = $this->getRequest();
		$customerName = $request->getParam('customer'); // 'description' from CUSTOMER_CODES (e.g. 'BUTTERFIELD BANK')
		$wf_model = new Application_Model_Workflow();
		if (!$customerName || $wf_model->isClientApprovedForServiceForm('SI2/firemon/serviceform', $customerName)) {
			$request = $this->getRequest();
			$this->view->ticketType = $request->getParam('ticketType');
			$this->view->taskId = $request->getParam('service_request_actions_id');
			if (isset($this->view->ticketType)) {
				$model = new Application_Model_Firemon();
				$this->view->regions = $model->getRegions();
				$this->view->timezones = $model->getTimezones();
				if ($this->view->ticketType !== 'BREAKFIX') {
					$this->view->protocols = $model->getPorts();
				}
			}
		} else {
			// REDIRECT TO STATIC BLUE INFO PAGE
			$this->render('forbidden');
		}
	}

	public function fetchdevicegroupsAction() {
		$this->_helper->layout()->disableLayout();
		$this->_helper->viewRenderer->setNoRender(true);
		$request = $this->getRequest();
		$domain_id = $request->getParam('id');
		$region = $request->getParam('region');
		$ret = $this->firstOpt;
		$select = $this->_db->select()
			->from("FIREMON_DEVICEGROUPS")
			->where("domain_id = ?", array($domain_id))
			->where("region = ?", array($region))
			->order("name")
		;
		$stmt = $this->_db->query($select);
		$device_groups = $stmt->fetchAll();
		foreach ($device_groups as $dg) {
			$ret .= '<option value="' . $dg['id'] . '">' . $dg['name'] . '</option>';
		}
		$ret .= '<option value="X">My device group isn\'t listed</option>';
		echo $ret;
	}
	
	public function fetchdomainsAction() {
		$this->_helper->layout()->disableLayout();
		$this->_helper->viewRenderer->setNoRender(true);
		$request = $this->getRequest();
		$region_id = $request->getParam('id');
		$ret = $this->firstOpt;
		$select = $this->_db->select()
			->from(array('FD' => 'FIREMON_DOMAINS'), array('FD.id', 'FD.name'))
			->where("FD.region = ?", array($region_id))
			->order("FD.name")
		;
		$stmt = $this->_db->query($select);
		$domains = $stmt->fetchAll();
		foreach ($domains as $d) {
			$ret .= '<option value="' . $d['id'] . '">' . $d['name'] . '</option>';
		}
		$ret .= '<option value="X">My domain isn\'t listed</option>';
		echo $ret;
	}
	
	public function fetchdevicesAction() {
		$this->_helper->layout()->disableLayout();
		$this->_helper->viewRenderer->setNoRender(true);
		$request = $this->getRequest();
		$dg_id = $request->getParam('id');
		$region = $request->getParam('region');
		$domain = $request->getParam('domain');
		$ret = $this->firstOpt;
		$select = $this->_db->select()
			->from(array('FD' => 'FIREMON_DEVICES'), array('FD.id', 'FD.name'))
			->where("FD.device_group = ?", array($dg_id))
			->where("FD.region = ?", array($region))
			->where("FD.domain_id = ?", array($domain))
			->order("FD.name")
		;
		$stmt = $this->_db->query($select);
		$devices = $stmt->fetchAll();
		foreach ($devices as $d) {
			$ret .= '<option value="' . $d['name'] . '">' . $d['name'] . '</option>';
		}
		$ret .= '<option value="X">My device isn\'t listed</option>';
		echo $ret;
	}
	
	public function updateallticketsAction() {
		$this->_helper->layout()->disableLayout();
		$this->_helper->viewRenderer->setNoRender(true);
		$select = $this->_db->select()
			->from(array('FT' => 'FIREMON_TICKETS'), array('FT.process_instance_id','FT.firemon_tickets_id','FT._application_url'))
			->where("FT.completed <> 1")
			->where("FT.status = 'submitted'")
		;
		$stmt = $this->_db->query($select);
		$tickets = $stmt->fetchAll();
		foreach ($tickets as $ticket) {
			echo "processInstanceId = " . $ticket['process_instance_id'] . "...";
			$ch = $this->getCurl('https://138.35.217.240/firemon/api/1.0/workflows/process-instance-details/' . $ticket['process_instance_id']);
			$t_status = json_decode(curl_exec($ch));
			curl_close($ch);

			if (isset($t_status->processInstanceId)) {
				// FIREMON_TICKETS_ACTIVITIES
				$v = $t_status->variables;
				$data = array(
					'_application_url' => $this->getVariable($v, '_applicationUrl'),
					'_authenticated_user' => $this->getVariable($v, '_authenticatedUser'),
					'_authorized_submitter_user' => $this->getVariable($v, '_authorizedSubmitterUser'),
					'_current_task_id' => $this->getVariable($v, '_currentTaskId'),
					'_design_user' => $this->getVariable($v, '_designUser'),
					'_domain_id' => $this->getVariable($v, '_domainId'),
					'_requester_id' => $this->getVariable($v, '_requesterId'),
					'_requester_name' => $this->getVariable($v, '_requesterName'),
					'billing_code_cost_center' => $this->getVariable($v, 'billingCodeCostCenter'),
					'business_key' => $t_status->businessKey,
					'business_owner' => $this->getVariable($v, 'businessOwner'),
					'business_unit' => $this->getVariable($v, 'businessUnit'),
					'carbon_copy' => $this->getVariable($v, 'carbonCopy'),
					'change_ctrl' => $this->getVariable($v, 'changeCtrl'),
					'change_ctrl_freeze' => $this->getVariable($v, 'changeCtrlFreeze'),
					'completed' => $t_status->completed,
					'custom_authorized_submitter_actions' => $this->getVariable($v, 'customAuthorizedSubmitterActions'),
					'custom_design_actions' => $this->getVariable($v, 'customDesignActions'),
					'customer' => $this->getVariable($v, 'customer'),
					'design_required' => $this->getVariable($v, 'designRequired'),
					'designer_assigned' => $this->getVariable($v, 'designerAssigned'),
					'designer_name' => $this->getVariable($v, 'designerName'),
					'display_name' => $this->getVariable($v, 'displayName'),
					'due_date' => $this->getVariable($v, 'dueDate'),
					'due_time_end' => $this->getVariable($v, 'dueTimeEnd'),
					'due_time_start' => $this->getVariable($v, 'dueTimeStart'),
					'duration' => $t_status->duration,
					'end_activity_id' => $t_status->endActivityId,
					'end_time' => $t_status->endTime,
					'external_ticket_id' => $this->getVariable($v, 'externalTicketId'),
					'firewall_host_name' => $this->getVariable($v, 'firewallHostName'),
					'impact' => $this->getVariable($v, 'impact'),
					'is_cancelled_ticket' => $this->getVariable($v, 'isCancelledTicket'),
					'justification' => $this->getVariable($v, 'justification'),
					'notes' => $this->getVariable($v, 'notes'),
					'priority' => $this->getVariable($v, 'priority'),
					'process_definition_id' => $t_status->processDefinitionId,
					'requester_email' => $this->getVariable($v, 'requesterEmail'),
					'requester_name' => $this->getVariable($v, 'requesterName'),
					'risk' => $t_status->risk,
					'start_activity_id' => $t_status->startActivityId,
					'start_notifications' => $this->getVariable($v, 'startNotifications'),
					'start_time' => $t_status->startTime,
					'start_user_id' => $t_status->startUserId,
					'summary' => $this->getVariable($v, 'summary'),
					'ticket_type' => $this->getVariable($v, 'ticketType'),
					'timezone' => $this->getVariable($v, 'timezone'),
					'timestamp' => new Zend_Db_Expr('NOW()')
				);
				$where['process_instance_id = ?'] = $ticket['process_instance_id'];
				$this->_db->update('FIREMON_TICKETS', $data, $where);

				// FIREMON_TICKETS_REQUIREMENTS
				// delete 
				$this->_db->delete('FIREMON_TICKETS_REQUIREMENTS', 'firemon_tickets_id = ' . $ticket['firemon_tickets_id']);

				// insert
				$i = 0;
				while ($this->getVariable($v, 'requirement.source.'.$i)) {
					$data = array(
						'firemon_tickets_id' => $ticket['firemon_tickets_id'],
						'source' => $this->getVariable($v, 'requirement.source.'.$i),
						'destination' => $this->getVariable($v, 'requirement.destination.'.$i),
						'service' => $this->getVariable($v, 'requirement.service.'.$i),
						'action' => $this->getVariable($v, 'requirement.action.'.$i),
						'expire_date' => $this->getVariable($v, 'requirement.expireDate.'.$i),
						'review_date' => $this->getVariable($v, 'requirement.reviewDate.'.$i),
						'comment' => $this->getVariable($v, 'requirement.comment_0.'.$i),
						'object_or_group' => $this->getVariable($v, 'requirement.objectOrGroup_0.'.$i),
						'uuid' => $this->getVariable($v, 'requirement.uuid.'.$i)
					);
					$this->_db->insert('FIREMON_TICKETS_REQUIREMENTS', $data);
					$i++;
				}

				// FIREMON_TICKETS_ACTIVITIES
				// delete 
				$this->_db->delete('FIREMON_TICKETS_ACTIVITIES', 'firemon_tickets_id = ' . $ticket['firemon_tickets_id']);

				// insert
				foreach ($t_status->activities as $activity) {
					$data = array(
						'firemon_tickets_id' => $ticket['firemon_tickets_id'],
						'activity_id' => $activity->activityId,
						'activity_name' => $activity->activityName,
						'activity_type' => $activity->activityType,
						'start_time' => $activity->startTime,
						'assignee' => $activity->assignee,
						'completed' => $activity->completed,
						'end_time' => $activity->endTime,
						'duration' => $activity->duration
					);
					$this->_db->insert('FIREMON_TICKETS_ACTIVITIES', $data);
				}

				// FIREMON_TICKETS_CURRENT_TASKS
				// delete 
				$this->_db->delete('FIREMON_TICKETS_CURRENT_TASKS', 'firemon_tickets_id = ' . $ticket['firemon_tickets_id']);

				// insert
				foreach ($t_status->currentTasks as $ct) {
					$data = array(
						'firemon_tickets_id' => $ticket['firemon_tickets_id'],
						'task_id' => $ct->taskId,
						'task_name' => $ct->taskName,
						'task_definition_key' => $ct->taskDefinitionKey,
						'owner' => $ct->identityLinkList,
						'assignee' => $ct->owner,
						'assignee' => $ct->assignee,
						'start_time' => $ct->startTime,
						'completed' => $ct->completed,
						'end_time' => $ct->endTime,
						'duration' => $ct->duration,
						'claim' => $ct->claim,
						'view' => $ct->view,
						'viewable' => $ct->viewable,
						'editable' => $ct->editable,
						'claimable' => $ct->claimable,
						'assignable' => $ct->assignable,
						'properties' => $ct->properties
					);
					$this->_db->insert('FIREMON_TICKETS_CURRENT_TASKS', $data);
				}

				// FIREMON_TICKETS_TASKS
				// delete 
				$this->_db->delete('FIREMON_TICKETS_TASKS', 'firemon_tickets_id = ' . $ticket['firemon_tickets_id']);

				// insert
				foreach ($t_status->tasks as $task) {
					$data = array(
						'firemon_tickets_id' => $ticket['firemon_tickets_id'],
						'task_id' => $task->taskId,
						'task_name' => $task->taskName,
						'owner' => $task->owner,
						'assignee' => $task->assignee,
						'start_time' => $task->startTime,
						'completed' => $task->completed,
						'end_time' => $task->endTime,
						'duration' => $task->duration
					);
					$this->_db->insert('FIREMON_TICKETS_TASKS', $data);
				}
				echo 'Done<br/>';
			} else {
				echo 'NO DATA<br/>';
			}
		}
	}

	public function getcurrentworkflowAction() {
		$this->_helper->layout()->disableLayout();
		$this->_helper->viewRenderer->setNoRender(true);
		$request = $this->getRequest();
		$domainId = $request->getParam('id',null);
		$ch = $this->getCurl('https://138.35.217.240/firemon/api/1.0/workflows/current?domainId=' . $domainId);
		$msg = curl_exec($ch);
		curl_close($ch);
		$response = json_decode($msg);
		echo '<html><body>processDefinitionKey = ' . $response->results[0]->processDefinitionKey . '</body></html>';
	}
	
	public function editAction() {
		$this->_helper->layout()->disableLayout();
		$request = $this->getRequest();
		$taskId = $request->getParam('taskid',null);
		$this->view->taskId = $taskId;
		$fm = new Application_Model_Firemon();
		$this->view->srid = $request->getParam('srid',null);
		$this->view->ticketinfo = $fm->getTicket($taskId);
		$this->view->regions = $fm->getRegions();
		$this->view->timezones = $fm->getTimezones();
		$this->view->referer = $request->getHeader('referer');						
	}
	
	public function editsaveAction() {
		$this->_helper->layout()->disableLayout();
		$this->_helper->viewRenderer->setNoRender(true);
		
		// FIREMON_TICKETS
		$data = array(
			'business_owner' => $_POST['businessOwner'],
			'business_unit' => $_POST['businessUnit'],
			'carbon_copy' => $_POST['carbonCopy'],
			'design_required' => $_POST['designRequired'],
			'device_group' => $_POST['DeviceGroup'],
			'domain' => $_POST['DomainName'],
			'due_date' => $_POST['dueDate'],
			'due_time_end' => $_POST['dueTimeEnd'],
			'due_time_start' => $_POST['dueTimeStart'],
			'external_ticket_id' => $_POST['externalTicketId'],
			'firewall_host_name' => $_POST['Device'],
			'impact' => $_POST['impact'],
			'justification' => $_POST['justification'],
			'notes' => $_POST['notes'],
			'priority' => $_POST['priority'],
			'region' => $_POST['region'],
			'summary' => $_POST['summary'],
			'timezone' => $_POST['timezone']
		);
		if ($_POST['ticketType'] !== 'BREAKFIX') {
			$data = array_merge($data, array(
				'change_ctrl' => $_POST['changeCtrl'],
				'change_ctrl_freeze' => $_POST['changeCtrlFreeze'],
			));
		}
		$this->_db->update('FIREMON_TICKETS', $data, 'netsrm_task_id = ' . $_POST['service_request_actions_id']);
		$redirectURL = $_POST['referer'];
		if (strpos($redirectURL, 'anchor') === false) {
			$redirectURL .= '/anchor/firemon_' . $_POST['srid'] . '_' . $_POST['service_request_actions_id'];
		}
		$this->_redirect($redirectURL);
	}
	
	public function saveticketAction() {
		$this->_helper->layout()->disableLayout();
		$this->_helper->viewRenderer->setNoRender(true);
		
		// FIREMON_TICKETS
		$data = array(
			'billing_code_cost_center' => $_POST['billingCode'],
			'business_owner' => $_POST['businessOwner'],
			'business_unit' => $_POST['businessUnit'],
			'carbon_copy' => $_POST['carbonCopy'],
			'compass_wbs' => $_POST['compassWBS'],
			'completed' => 0,
			'customer' => $_POST['customer'],
			'design_required' => $_POST['designRequired'],
			'device_group' => $_POST['DeviceGroup'],
			'domain' => $_POST['DomainName'],
			'due_date' => $_POST['dueDate'],
			'due_time_end' => $_POST['dueTimeEnd'],
			'due_time_start' => $_POST['dueTimeStart'],
			'external_ticket_id' => $_POST['externalTicketId'],
			'firewall_host_name' => $_POST['Device'],
			'impact' => $_POST['impact'],
			'justification' => $_POST['justification'],
			'netsrm_service_request_id' => $_POST['netsrmServiceRequestId'],
			'netsrm_task_id' => $_POST['service_request_actions_id'],
			'notes' => $_POST['notes'],
			'priority' => $_POST['priority'],
			'region' => $_POST['region'],
			'requester_email' => $_POST['requesterEmail'],
			'requester_name' => $_POST['requesterName'],
			//'start_notifications' => 'd_none',
			'start_notifications' => $_POST['startNotifications'],
			'status' => 'saved',
			'summary' => $_POST['summary'],
			'ticket_type' => $_POST['ticketType'],
			'timezone' => $_POST['timezone'],
			'watch_notify' => $_POST['watchNotifyValue']
		);
		if ($_POST['ticketType'] !== 'BREAKFIX') {
			$data = array_merge($data, array(
				'change_ctrl' => $_POST['changeCtrl'],
				'change_ctrl_freeze' => $_POST['changeCtrlFreeze'],
			));
		}
		
		$this->_db->insert('FIREMON_TICKETS', $data);
		$lastID = $this->_db->lastInsertId();						
		
		// FIREMON_TICKETS_REQUIREMENTS
		if ($_POST['ticketType'] === 'FIREWALL' || $_POST['ticketType'] === 'MISC' && $_POST['enable_requirements'] === '1') {
			$count = 0;
			for ($i=1; $i<=30; $i++) {
				$hasData = false;
				$source = '';
				$destination = '';
				$service = '';
				$action = '';
				$expireDate = '';
				$reviewDate = '';
				for ($j=1; $j<=30; $j++) {
					if (isset($_POST['source_' . $i . '-' . $j])) {
						$hasData = true;
						if (!empty($source)) $source .= ';';
						$source .= $_POST['source_' . $i . '-' . $j];
					}
					if (isset($_POST['destination_' . $i . '-' . $j])) {
						$hasData = true;
						if (!empty($destination)) $destination .= ';';
						$destination .= $_POST['destination_' . $i . '-' . $j];
					}
					if (isset($_POST['protocol_' . $i . '-' . $j])) {
						if (!empty($service)) $service .= ';';
						$service .= $_POST['protocol_' . $i . '-' . $j] . '/' . $_POST['port_' . $i . '-' . $j . 'flex'];
					}
				}
				if ($hasData) {
					$action = $_POST['action_' . $i];
					$expireDate = $_POST['expireDate_' . $i];
					$reviewDate = $_POST['reviewDate_' . $i];
					$data = array(
						'firemon_tickets_id' => $lastID,
						'requirement_index' => $count,
						'source' => $source,
						'destination' => $destination,
						'service' => $service,
						'action' => $action,
						'expire_date' => $expireDate,
						'review_date' => $reviewDate
					);
					$this->_db->insert('FIREMON_TICKETS_REQUIREMENTS', $data);
					$count++;
				}
			}
		}
		
		// AFTER...
		$this->render('saved');
	}
	
	private function getVariable($obj, $theVar) {
		foreach ($obj as $var) {
			if ($var->variableName === $theVar) {
				return $var->variableValue;
			}
		}
		return null;
	}
	
	public function flexboxportAction() 
	{
		$this->_helper->layout()->disableLayout();
		$this->_helper->viewRenderer->setNoRender(true);

		$request = $this->getRequest();
		$q = strtoupper($request->getParam('q',null));
		$p = intval($request->getParam('p',1));
		$s = intval($request->getParam('s',10));

		// GET RESULTS
		$select = $this->_db->select()
			->from("FIREMON_PORTS")
			->where("UPPER(port_id) LIKE ?", "%$q%")
			->orWhere("UPPER(protocol) LIKE ?", "%$q%")
			->orWhere("UPPER(service_name) LIKE ?", "%$q%")
			->orWhere("UPPER(aliases) LIKE ?", "%$q%")
			->orWhere("UPPER(comment) LIKE ?", "%$q%")
			->limit($s, ($p-1)*$s) 
		;
		$stmt = $this->_db->query($select);
		$retArr = $stmt->fetchAll();
		
		// GET RESULTS COUNT
		$select = "
			SELECT 
				COUNT(*) AS result_count
			FROM 
				FIREMON_PORTS 
			WHERE 
				UPPER(port_id) LIKE '%" . $q . "%' OR 
				UPPER(protocol) LIKE '%" . $q . "%' OR 
				UPPER(service_name) LIKE '%" . $q . "%' OR 
				UPPER(aliases) LIKE '%" . $q . "%' OR 
				UPPER(comment) LIKE '%" . $q . "%'
		";
		$stmt = $this->_db->query($select);
		$count = $stmt->fetch();
		
		echo json_encode(
			array(
				'results' => $retArr,
				'total' => $count['result_count']
			)
		);
	}
	
	
}
?>
