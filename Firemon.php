<?php

class Application_Model_Firemon extends Zend_Db_Table_Abstract
{
	private $db;

	public function __construct() {
		$this->db = $this->getDefaultAdapter();
	}
	
	public function init() {}

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
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		curl_setopt($ch, CURLOPT_SSLVERSION, 1);
		return $ch;
	}

	public function getRegions() {
		$select = $this->db->select()
			->from("FIREMON_REGIONS")
			->where("enabled = 1")
			->order("display_name")
		;
		$stmt = $this->db->query($select);
		$regions = $stmt->fetchAll();
		$rstring = '';
		foreach ($regions as $r) {
			$rstring .= '<option value="' . $r['firemon_regions_id'] . '">' . $r['display_name'] . '</option>';
		}
		return $rstring;
	}
	
	public function getTimezones() {
		$select = $this->db->select()
			->from("TIMEZONES")
			->where("firemon_id IS NOT NULL")
			->order("id")
		;
		$stmt = $this->db->query($select);
		$timezones = $stmt->fetchAll();
		$tzstring = '';
		foreach ($timezones as $tz) {
			$tzstring .= '<option value="' . $tz['firemon_id'] . '">' . $tz['offset'] . '</option>';
		}
		return $tzstring;
	}
	
	public function getPorts() {
		$select = $this->db->select()
			->distinct()
			->from("FIREMON_PORTS", "protocol")
		;
		$stmt = $this->db->query($select);
		$protocols = $stmt->fetchAll();
		$protocol_string = '';
		foreach ($protocols as $p) {
			$protocol_string .= '<option value="' . $p['protocol'] . '">' . $p['protocol'] . '</option>';
		}
		return $protocol_string;
	}
	
	public function addAttachment($host, $pid, $attachment) {
		// ATTACHMENTS /firemon/api/1.0/workflows/task/{taskId}/{processInstanceId}/attachment
		$data = array(
			"fileDescription" => $attachment['data'],
			"fileName" => $attachment['name'],
			"attachmentDescription" => "ATTACHMENT FROM ORIGINATOR (NetSRM)"
		);
		$ch = $this->getCurl($host . '/firemon/api/1.0/workflows/task/999/' . $pid . '/attachment', 'PUT');
		curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: multipart/form-data'));
						
		// EXECUTE CURL AND PARSE RESPONSE
		$msg = curl_exec($ch);
		curl_close($ch);
		return $msg;
	}
	
	public function getTicket($taskId) {
		$select = $this->db->select()
			->from(array('F' => 'FIREMON_TICKETS'))
			->joinLeft(array('FD' => 'FIREMON_DOMAINS'), 'F.domain = FD.id AND F.region = FD.region', array('domain_timestamp'=>'FD.timestamp', 'domain_name'=>'FD.name'))
			->joinLeft(array('FDG' => 'FIREMON_DEVICEGROUPS'), 'F.device_group = FDG.id', array('device_group_name'=>'FDG.name'))
			->joinLeft(array('FR' => 'FIREMON_REGIONS'), 'F.region = FR.firemon_regions_id', array('region_name'=>'FR.display_name', 'ip_address'=>'FR.ip_address', 'host'=>'FR.host'))
			->where("F.netsrm_task_id = ?", array($taskId))
		;
		$stmt = $this->db->query($select);
		return $stmt->fetch();
	}
	
	public function getRequirements($taskId) {
		$subselect = $this->db->select()
			->from('FIREMON_TICKETS','firemon_tickets_id')
			->where('netsrm_task_id = ?', array($taskId))
		;
		$select = $this->db->select()
			->from('FIREMON_TICKETS_REQUIREMENTS')
			->where("firemon_tickets_id = ($subselect)")
			->order('requirement_index')
		;
		$stmt = $this->db->query($select);
		return $stmt->fetchAll();
	}
	
	public function getActivities($taskId) {
		$subselect = $this->db->select()
			->from('FIREMON_TICKETS','firemon_tickets_id')
			->where('netsrm_task_id = ?', array($taskId))
		;
		$select = $this->db->select()
			->from('FIREMON_TICKETS_ACTIVITIES')
			->where("firemon_tickets_id = ($subselect)")
			->order(array('start_time'))
		;
		$stmt = $this->db->query($select);
		return $stmt->fetchAll();
	}
	
	public function getTasks($taskId) {
		$subselect = $this->db->select()
			->from('FIREMON_TICKETS','firemon_tickets_id')
			->where('netsrm_task_id = ?', array($taskId))
		;
		$select = $this->db->select()
			->from('FIREMON_TICKETS_TASKS')
			->where("firemon_tickets_id = ($subselect)")
			->order(array('start_time'))
		;
		$stmt = $this->db->query($select);
		return $stmt->fetchAll();
	}
	
	public function getCurrentTasks($taskId) {
		$subselect = $this->db->select()
			->from('FIREMON_TICKETS','firemon_tickets_id')
			->where('netsrm_task_id = ?', array($taskId))
		;
		$select = $this->db->select()
			->from('FIREMON_TICKETS_CURRENT_TASKS')
			->where("firemon_tickets_id = ($subselect)")
			->order(array('start_time'))
		;
		$stmt = $this->db->query($select);
		return $stmt->fetchAll();
	}
	
	/**
	 * Set the task id for the FIREMON_TICKET after the user adds the task to the cart.
	 * @param type $requestId The service request id (SERVICE_REQUESTS)
	 * @param type $taskId The task id (SERVICE_REQUEST_ACTIONS)
	 */
	public function setTaskId($requestId, $taskId) {
		$data = array(
			'netsrm_task_id' => $taskId
		);
		$where['netsrm_service_request_id = ?'] = $requestId;
		$where[] = 'netsrm_task_id IS NULL';
		$this->db->update('FIREMON_TICKETS', $data, $where);
	}
	
	public function getFiremonHost($ticketId) {
		$select = $this->db->select()
			->from(array('FT' => 'FIREMON_TICKETS'), 'FT.firemon_tickets_id')
			->join(array('FR' => 'FIREMON_REGIONS'), 'FR.firemon_regions_id = FT.region', array('FR.ip_address','FR.host'))
			->where("FT.firemon_tickets_id = ?", array($ticketId))
		;
		$stmt = $this->db->query($select);
		$result = $stmt->fetch();
		return $result['host'];
		//return $result['ip_address'];
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
	public function createFiremonTicket($taskId) {
		$SSINamespace = new Zend_Session_Namespace('SSINamespace');
		$result = $this->getTicket($taskId);
		
		//if (!empty($result['firewall_host_name']) && !empty($result['region']) && !empty($result['domain'])) {
		if (!empty($result['region']) && !empty($result['domain'])) {
			// IF DEVICE NAME NOT SPECIFIED, INSERT PLACEHOLDER
			$firewallHostName = $result['firewall_host_name'];
			if (empty($firewallHostName)) {
				$firewallHostName = "REQUESTOR DID NOT SPECIFY";
			}

			// GET FIREMON INSTANCE
			$firemon_host = 'https://' . $result['host'];
			//$firemon_host = 'https://' . $result['ip_address'];
			
			// LOOKUP CURRENT WORKFLOW FOR DOMAIN (processDefinitionKey)
			$ch = $this->getCurl($firemon_host . '/firemon/api/1.0/workflows/current?domainId=' . $result['domain']);
			$msg = curl_exec($ch);
			$response = json_decode($msg);
			
			if (isset($response->results[0]->processDefinitionKey)) {
				// BUILD DATA ARRAY
				$changeCtrl = (!empty($result['change_ctrl'])) ? $result['change_ctrl'] : "";
				$changeCtrlFreeze = (!empty($result['change_ctrl_freeze'])) ? $result['change_ctrl_freeze'] : "";
				$data = array(
					// REQUIRED
					"_applicationUrl"	=> $firemon_host . "/policyplanner",
					"processDefinitionKey" => $response->results[0]->processDefinitionKey,
					"billingCodeCostCenter" => $result['billing_code_cost_center'] . ', Compass WBS: ' . $result['compass_wbs'],
					"firewallHostName" => $firewallHostName,
					"impact" => $result['impact'],
					"requesterEmail" => $result['requester_email'],
					"requesterName" => $result['requester_name'],
					"retryCounter" => 1,
					"startNotifications" => $result['start_notifications'],
					"summary" => "NetSRM (" . $result['netsrm_service_request_id'] . "." . $result['netsrm_task_id'] . "): " . $result['summary'],
					"ticketType" => $result['ticket_type'],

					// OPTIONAL
					"businessOwner" => $result['business_owner'],
					"businessUnit" => $result['business_unit'],
					"carbonCopy" => $result['carbon_copy'],
					"changeCtrl" => $changeCtrl,
					"changeCtrlFreeze" => $changeCtrlFreeze,
					"customer" => $result['customer'],
					"designRequired" => $result['design_required'],
					"dueDate" => $result['due_date'],
					"dueTimeEnd" => $result['due_time_end'],
					"dueTimeStart" => $result['due_time_start'],
					"externalTicketId" => $result['external_ticket_id'],
					"justification" => $result['justification'],
					"notes" => $result['notes'],
					"priority" => $result['priority'],
					"timezone" => $result['timezone'],
				);

				// ADD REQUIREMENTS
				$select = $this->db->select()
					->from(array('FR' => 'FIREMON_TICKETS_REQUIREMENTS'))
					->where("FR.firemon_tickets_id = ?", array($result['firemon_tickets_id']))
					->order("FR.requirement_index")
				;
				$stmt = $this->db->query($select);
				$result2 = $stmt->fetchAll();
				foreach ($result2 as $req) {
					$i = $req['requirement_index'];
					$data2 = array(
						"requirement.source." . $i => $req['source'],
						"requirement.destination." . $i => $req['destination'],
						"requirement.service." . $i => $req['service'],
						"requirement.action." . $i => $req['action'],
						"requirement.expireDate." . $i => $req['expire_date'],
						"requirement.reviewDate." . $i => $req['review_date']
					);
					$data = array_merge($data, $data2);
				}

				// JSON ENCODE THE ARRAY
				$post = json_encode($data);

				// SET UP CURL
				$ch = $this->getCurl($firemon_host . '/firemon/api/1.0/workflows/process-instance/', 'POST');
				curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
				curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));

				// EXECUTE CURL AND PARSE RESPONSE
				$msg = curl_exec($ch);
				$response = json_decode($msg);
				curl_close($ch);

				// UPDATE FIREMON_TICKETS WITH PROCESS INSTANCE ID, BUSINESS KEY, PROCESS DEFINITION ID, STATUS = 'submitted'
				if ($response->processInstanceId) {
					// UPDATE FIREMON_TICKET WITH DATA FROM API RESPONSE
					$data3 = array(
						'status' => 'submitted',
						'process_instance_id' => $response->processInstanceId,
						'business_key' => $response->businessKey,
						'process_definition_id' => $response->processDefinitionId
					);
					$update = $this->db->update('FIREMON_TICKETS', $data3, 'netsrm_task_id = ' . $taskId);

					// WATCH/NOTIFY
					$wn = $result['watch_notify'];
					if ($wn === '1' || $wn === '2') {
						$has_email = ($wn === '2') ? '1' : '0';
						$n_model = new Application_Model_Notifications();
						$n_model->addNotify(4, $taskId, $has_email, array($SSINamespace->user_id), array(), array());
					}

					return array($firemon_host, $response->processInstanceId);
				} else {
					return 'ERROR: $response->processInstanceId is NULL. Error message from FireMon: ' . $msg;
				}
			} else {
				return 'ERROR: $response->results[0]->processDefinitionKey is NULL';
			}
		} else {
			//return 'ERROR: getTicket() data returned null for values "firewall_host_name", "region" and/or "domain"';
			return 'ERROR: getTicket() data returned null for values "region" and/or "domain"';
		}
	}
	
}	
?>