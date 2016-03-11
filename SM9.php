<?php
/**
 * Extend SoapClient in order to strip Byte-Order-Mark (BOM) character from XML response
 * https://www.mikemackintosh.com/fixing-soap-exception-no-xml/
 */
class MySoapClient extends SoapClient
{
	public function __doRequest($req, $location, $action, $version = SOAP_1_1) {
		$xml = explode("\r\n", parent::__doRequest($req, $location, $action, $version));
		$response = preg_replace('/^(\x00\x00\xFE\xFF|\xFF\xFE\x00\x00|\xFE\xFF|\xFF\xFE|\xEF\xBB\xBF)/', "", $xml[5]);
		return $response;
	}	
}

class Application_Model_SM9 extends Zend_Db_Table_Abstract
{
	private $CISHost;
	private $db;
	
	const DATACONCEPT_WORKORDER = 'WORK ORDER';
	const DATACONCEPT_CHANGE    = 'CHANGE';
	const DATACONCEPT_INCIDENT  = 'INCIDENT';
	
	const TRANSACTION_TYPE_CANCEL     = 0;
	const TRANSACTION_TYPE_CLOSE      = 1;
	const TRANSACTION_TYPE_ESCALATE   = 2;
	const TRANSACTION_TYPE_GENERATEID = 3;
	const TRANSACTION_TYPE_INSERT     = 4;
	const TRANSACTION_TYPE_LINK       = 5;
	const TRANSACTION_TYPE_QUERY      = 6;
	const TRANSACTION_TYPE_REJECT     = 7;
	const TRANSACTION_TYPE_RESOLVE    = 8;
	const TRANSACTION_TYPE_SUSPEND    = 9;
	const TRANSACTION_TYPE_UPDATE     = 10;
	const TRANSACTION_TYPE_UPSERT     = 11;
	
	const URL_WORKORDER  = '/ExternalRestRR-Workorderv2/api/Workorder';
	const URL_CHANGE     = '/ExternalRestRR-ChangeV2/api/Change';
	const URL_INCIDENT   = '/ExternalRestRR-IncidentV1/api/Incident';
	const URL_ATTACHMENT = '/ExternalRR-AttachmentV1/AttachmentService.svc';
	
	const AUTH_USERNAME = 'cishpecsportal';
	const AUTH_PASSWORD = '!4idd.edd.sdd';
	
	const NS_SECURITY = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';
	const NS_ATTACHMENT_COMMON_V1  = 'http://HP.CIS.External.Attachment.Common.V1';
	const NS_MESSAGE_CORE_V4       = 'http://HP.CIS.External.Message.Core.V4';
	const NS_SERVICE_ATTACHMENT_V1 = 'http://Hp.Cis.External.Service.AttachmentV1';
	const NS_MESSAGE_ATTACHMENT_V1 = 'http://HP.CIS.External.Message.Attachment.V1';
	
	public function __construct() {
		$this->CISHost = 'https://eit100inv.eit.ssn.hp.com:8447';
		//$this->CISHost = 'https://eit200inv.eit.ssn.hp.com:8447';
		//$this->CISHost = 'https://eitinvpv202.eit.ssn.hp.com:8447';
		$this->db = Zend_Registry::get('db');
	}
	
	public function init() {
		$this->db = Zend_Registry::get('db');
	}
	
	public function addAttachment($ticketId, $ticketData, $attachment) {
		// Security
		$username = new SoapVar(self::AUTH_USERNAME, XSD_STRING, null, null, 'Username', self::NS_SECURITY);
		$password = new SoapVar(self::AUTH_PASSWORD, XSD_STRING, null, null, 'Password', self::NS_SECURITY);	
		$usernameToken = new SoapVar(array($username,$password), SOAP_ENC_OBJECT, null, null, 'UsernameToken', self::NS_SECURITY);
		$security = new SoapVar(array($usernameToken), SOAP_ENC_OBJECT, null, null, 'Security', self::NS_SECURITY);
		
		// Attachment
		$description = new SoapVar($attachment['name'], XSD_STRING, null, null, 'Description', self::NS_ATTACHMENT_COMMON_V1);
		$name = new SoapVar($attachment['name'], XSD_STRING, null, null, 'Name', self::NS_ATTACHMENT_COMMON_V1);
		$refNum = new SoapVar($ticketId, XSD_STRING, null, null, 'ReferenceNumber', self::NS_ATTACHMENT_COMMON_V1);
		$attachmentVar = new SoapVar(array($description,$name,$refNum), SOAP_ENC_OBJECT, null, null, 'Attachment', self::NS_MESSAGE_ATTACHMENT_V1);
		
		// MessageHeader
		$dataConcept = new SoapVar($ticketData['data_concept'], XSD_STRING, null, null, 'DataConcept', self::NS_MESSAGE_CORE_V4);	
		$transactionType = new SoapVar("INSERT", XSD_STRING, null, null, 'TransactionType', self::NS_MESSAGE_CORE_V4);	
		$userRole = new SoapVar("InternalUser", XSD_STRING, null, null, 'UserRole', self::NS_MESSAGE_CORE_V4);	
		$userId = new SoapVar("ECSEIT2.USER2@GMAIL.COM", XSD_STRING, null, null, 'UserId', self::NS_MESSAGE_CORE_V4);	
		$userContext = new SoapVar(array($userRole,$userId), SOAP_ENC_OBJECT, null, null, 'UserContext', self::NS_MESSAGE_CORE_V4);
		$customerCode = new SoapVar($ticketData['customer'], XSD_STRING, null, null, 'CustomerCode', self::NS_MESSAGE_CORE_V4);	
		$messageHeader = new SoapVar(array($dataConcept,$transactionType,$userContext,$customerCode), SOAP_ENC_OBJECT, null, null, 'MessageHeader', self::NS_MESSAGE_CORE_V4);
		
		// AttachmentMessage
		$attachmentMessage = new SoapVar(array($messageHeader,$attachmentVar), SOAP_ENC_OBJECT, null, null, 'AttachmentMessage', self::NS_SERVICE_ATTACHMENT_V1);

		// BODY
		$body = array(
			"CreateMessage" => array(
				"MessageStream" => $attachment['data']
			)
		);
		
		// OPTIONS (SoapClient)
		$options = array(
			"trace" => 1,
			"exceptions" => 0
		);
		
		// CREATE SOAP CLIENT AND CALL CreateAttachment function
		$client = new MySoapClient($this->CISHost . self::URL_ATTACHMENT . "?wsdl", $options);
		$headers = array();
		$headers[] = new SoapHeader(self::NS_SERVICE_ATTACHMENT_V1, "AttachmentMessage", $attachmentMessage);
		$headers[] = new SoapHeader(self::NS_SECURITY, "Security", $security);
		$x = $client->__soapCall('CreateAttachment', $body, $options, $headers);
		//echo "GET LAST REQUEST: " . $client->__getLastRequest();
		//echo "<br/><br/>GET LAST REQUEST HEADERS: " . $client->__getLastRequestHeaders();
		echo "DONE! :-)<p>SM9 Ticket ID: " . $ticketId . "<br/>Attachment Name: " . $attachment['name'];
		return $x;
	}

	public function getAttachment($data) {
	}
	
	/* PRIVATE FUNCTIONS */
	private function getCurl($url, $method='GET') {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $this->CISHost . $url);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);		
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($ch, CURLOPT_HEADER, false);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
		curl_setopt($ch, CURLOPT_USERPWD, self::AUTH_USERNAME . ':' . self::AUTH_PASSWORD);
		//curl_setopt($ch, CURLOPT_USERPWD, "cishpecsportal:!4idd.edd.sdd");
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));		
		return $ch;
	}
	
	private function getMessageHeader($dataConcept, $transactionType, $customerCode, $userId='ECSEIT2.USER2@GMAIL.COM') {
		// BUILD SEARCH REQUEST OBJECT
		$post = new stdClass();
		$post->MessageHeader = new stdClass();
		$post->MessageHeader->DataConcept = $dataConcept;
		$post->MessageHeader->TransactionType = $transactionType; 
		//$post->MessageHeader->Version = "V2"; 
		$post->MessageHeader->UserContext = new stdClass();
		//$post->MessageHeader->UserContext->UserRole = "InternalUser";
		$post->MessageHeader->UserContext->UserRole = 0;
		$post->MessageHeader->UserContext->UserId = $userId;
		//$post->MessageHeader->UserContext->CompanyCode = 'DEMO';
		//$post->MessageHeader->UserContext->CompanyCode = 'MSCUST-A';
		//$post->MessageHeader->UserContext->CompanyCode = 'HP ECS EIT 2';
		//$post->MessageHeader->UserContext->CompanyCode = 'HP';
		$post->MessageHeader->CustomerCode = $customerCode; 
		//$post->MessageHeader->CustomerCode = 'MSCUST-A'; // WORKS FOR INCIDENT
		//$post->MessageHeader->CustomerCode = 'HP ECS EIT 2'; // WORKS FOR CHANGE (AND WORK ORDER?)
		//$post->MessageHeader->CustomerCode = 'DEMO';
		
		return $post;
	}
	
	private function getSearchRequestObject($data, $dataConcept) {
		// GET SEARCH MESSAGE HEADER
		$post = $this->getMessageHeader($dataConcept, self::TRANSACTION_TYPE_QUERY, $data['customer']); // 6 = 'QUERY'
		
		// ADD QUERYBLOCK
		$post->MessageHeader->QueryBlock = new stdClass();
		$post->MessageHeader->QueryBlock->MaxPageSize = 0;
		
		// ADD CRITERIA
		$criteria = array();
		$criteria[0] = new stdClass();
		$criteria[0]->Field = $data['Field'];
		$criteria[0]->Operator = intval($data['Operator']);
		$criteria[0]->Value = $data['Value'];
		$criteria[0]->GroupBy = intval($data['GroupBy']);
		$post->MessageHeader->QueryBlock->Criteria = $criteria;
		
		// RETURN
		return json_encode($post);
	}
	
	private function getSearchResults($obj, $objDesc) {
		if ($obj) {
			$messageResult = $obj->MessageHeader->MessageResult;
			if ($messageResult->ReturnCode === -1) { // FAIL
				return $messageResult->ReturnMessage;
			} else if ($messageResult->ReturnCode === 9) { // NO RECORDS FOUND
				return "No records found.";
			} else if ($messageResult->ReturnCode === 0) { // SUCCESS
				$lis_model = new Application_Model_LIS();
				$objArray = $lis_model->object_to_array($objDesc);
				return $objArray;
			} else if ($obj->Message) {
				return $obj->Message;
			}
		}
		return "ERROR: CURL FAILED";
	}
	
	/************************* WORK ORDERS ***************************/
	public function searchWorkOrders($data) {
		// PREPARE CURL
		$ch = $this->getCurl(self::URL_WORKORDER, 'POST');
		$json_data = $this->getSearchRequestObject($data, self::DATACONCEPT_WORKORDER);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
		
		// EXECUTE CURL
		$workOrders_raw = curl_exec($ch);
		$workOrders = json_decode($workOrders_raw);
		
		// CHECK FOR ERRORS
		if ($workOrders) {
			//return $this->getSearchResults($workOrders, $workOrders->WorkOrders);
			return var_dump($workOrders->WorkOrders[0]);
		} else {
			return $workOrders_raw;
		}
	}
	
	public function createWorkOrder($data, $ticketId) {
		return $this->executeTicket($data, self::URL_WORKORDER, self::DATACONCEPT_WORKORDER, self::TRANSACTION_TYPE_INSERT, $ticketId);
	}
	
	public function updateWorkOrder($data) {
		return $this->executeTicket($data, self::URL_WORKORDER, self::DATACONCEPT_WORKORDER, self::TRANSACTION_TYPE_UPDATE);
	}
	
	public function closeWorkOrder($data) {
		return $this->executeTicket($data, self::URL_WORKORDER, self::DATACONCEPT_WORKORDER, self::TRANSACTION_TYPE_CLOSE);
	}

	private function getWorkOrderRequestObject($data, $transactionType) {
		// GET MESSAGE HEADER
		$post = $this->getMessageHeader(self::DATACONCEPT_WORKORDER, $transactionType, $data['customer']); 
		
		// BUILD INSERT OBJECT
		$post->WorkOrders = array();
		
		/*** REQUIRED ***/
		$wo = new stdClass();
		if ($transactionType !== self::TRANSACTION_TYPE_CLOSE) {
			$wo->Name = $data['name'];
			$wo->ServiceProvider = "BOB TEST SERVICE PROVIDER UPDATE!";

			// affectsConfigurationItems
			$wo->affectsConfigurationItems = array();
			$wo->affectsConfigurationItems[0] = new stdClass();
			$wo->affectsConfigurationItems[0]->ConfigurationItemName = $data['affected_ci'];
			if ($data['affected_ci2']) {
				$wo->affectsConfigurationItems[1] = new stdClass();
				$wo->affectsConfigurationItems[1]->ConfigurationItemName = $data['affected_ci2'];
			}
			if ($data['affected_ci3']) {
				$wo->affectsConfigurationItems[2] = new stdClass();
				$wo->affectsConfigurationItems[2]->ConfigurationItemName = $data['affected_ci3'];
			}
			//$wo->affectsConfigurationItems[0]->ConfigurationItemName = "temp-0046.1715.1269.ecs.hp.com";

			// ItemDetail (PartNumber, isRequestedFor, AssignedGroupName)
			$wo->ItemDetail = new stdClass();
			$wo->ItemDetail->PartNumber = "90038";
			$wo->ItemDetail->isRequestedFor = new stdClass();
			$wo->ItemDetail->isRequestedFor->PartyName = "ECSEIT2.USER2@GMAIL.COM";
			$wo->ItemDetail->AssignedGroupName = new stdClass();
			$wo->ItemDetail->AssignedGroupName->PartyName = "MS.HP.FULFILLMENT";

			/*** OPTIONAL ***/
		}

		// ID
		if ($transactionType !== self::TRANSACTION_TYPE_INSERT) {
			$wo->WorkOrderId = $data['id'];
		}
		
		// RETURN
		$post->WorkOrders[0] = $wo;
		return json_encode($post);
	}
	
	/************************* CHANGE REQUESTS ***************************/
	public function searchChanges($data) {
		// PREPARE CURL
		$ch = $this->getCurl(self::URL_CHANGE, 'POST');
		$json_data = $this->getSearchRequestObject($data, self::DATACONCEPT_CHANGE);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
		
		// EXECUTE CURL
		$changes_raw = curl_exec($ch);
		$changes = json_decode($changes_raw);
		
		// CHECK FOR ERRORS
		//return $this->getSearchResults($changes, $changes->Changes);
		//return var_dump($changes->Changes[0]);
		return $changes->Changes[0];
	}
	
	public function createChange($data, $ticketId) {
		return $this->executeTicket($data, self::URL_CHANGE, self::DATACONCEPT_CHANGE, self::TRANSACTION_TYPE_INSERT, $ticketId);
	}
	
	public function updateChange($data) {
		return $this->executeTicket($data, self::URL_CHANGE, self::DATACONCEPT_CHANGE, self::TRANSACTION_TYPE_UPDATE);
	}
	
	public function closeChange($data) {
		return $this->executeTicket($data, self::URL_CHANGE, self::DATACONCEPT_CHANGE, self::TRANSACTION_TYPE_CLOSE);
	}
	
	private function getChangeRequestObject($data, $transactionType) {
		// GET MESSAGE HEADER
		$post = $this->getMessageHeader(self::DATACONCEPT_CHANGE, $transactionType, $data['customer']); 
		
		// BUILD INSERT OBJECT
		$post->Changes = array();
		
		/*** REQUIRED ***/
		$change = new stdClass();
		if ($transactionType !== self::TRANSACTION_TYPE_CLOSE) {
			$change->Name = $data['name'];
			$change->Description = $data['description'];
			$change->CategoryOfChange = $data['change_type'];
			$change->RiskAssessment = $data['risk_assessment'];
			//$change->RiskAssessment = "1";
			//$change->RiskAssessment = "low_risk";

			// affectsConfigurationItems
			$change->affectsConfigurationItems = array();
			$change->affectsConfigurationItems[0] = new stdClass();
			//$change->affectsConfigurationItems[0]->ConfigurationItemName = "temp-0046.1715.1269.ecs.hp.com";
			$change->affectsConfigurationItems[0]->ConfigurationItemName = $data['affected_ci'];
			if ($data['affected_ci2']) {
				$change->affectsConfigurationItems[1] = new stdClass();
				$change->affectsConfigurationItems[1]->ConfigurationItemName = $data['affected_ci2'];
			}
			if ($data['affected_ci3']) {
				$change->affectsConfigurationItems[2] = new stdClass();
				$change->affectsConfigurationItems[2]->ConfigurationItemName = $data['affected_ci3'];
			}

			// ChangeCommon 
			$change->ChangeCommon = new stdClass();
			$change->ChangeCommon->hasCoordinatorWorkgroup = new stdClass();
			//$change->ChangeCommon->hasCoordinatorWorkgroup->PartyName = "GROUP-ECSP";
			$change->ChangeCommon->hasCoordinatorWorkgroup->PartyName = $data['coordinator_group'];

			// hasSupervisorWorkgroup
			$change->hasSupervisorWorkgroup = new stdClass();
			//$change->hasSupervisorWorkgroup->PartyName = "MS COORDINATOR";
			$change->hasSupervisorWorkgroup->PartyName = $data['supervisor_group'];

			/*** OPTIONAL ***/
			//$change->ImpactScope = 1;
			$change->Urgency = $data['urgent'];
			//$change->ChangeStatus = "initial";
			$change->ChangeStatus = $data['status'];
			//$change->PlannedEndDate = "2015-03-22T23:59:00.000+05:30";
			//$change->PlannedStartDate = "2015-03-21T23:59:00.000+05:30";
			//$change->RequestedDate = "2015-03-24T23:59:00.000+05:30";
			//$change->RequestedDate = "/Date(1398931200000)/";
			$change->BackoutPlan = $data['backup_plan'];
			$change->ImplementationComment = $data['impl_plan'];
			$change->ReasonForChange = $data['reason'];

			// isRequestedBy
			$change->isRequestedBy = new stdClass();
			$change->isRequestedBy->PartyName = "ECSEIT2.USER2@GMAIL.COM";
	/*
			// hasExtensions 
			$change->hasExtensions = new stdClass();
			$change->hasExtensions->hasExtension = new stdClass();
			$change->hasExtensions->hasExtension->ExtensionName = "SMRWSChangeExtensions";
			$change->hasExtensions->hasExtension->NamespaceURL = "http://schemas.hp.com/es/itsm/HPChangeExtensions.V1";
			$change->hasExtensions->hasExtension->ObjectExtensionContent = new stdClass();
			$change->hasExtensions->hasExtension->ObjectExtensionContent->IsBillable = "true";
			$change->hasExtensions->hasExtension->ObjectExtensionContent->TemplateName = "CM_NORMAL_10797_GLOBAL_TEST";
	*/	
		}
		
		// ID
		if ($transactionType !== self::TRANSACTION_TYPE_INSERT) {
			$change->ChangeId = $data['id'];
		}
		
		// RETURN
		$post->Changes[0] = $change;
		return json_encode($post);
	}
	
	/************************* INCIDENTS ***************************/
	public function searchIncidents($data) {
		// PREPARE CURL
		$ch = $this->getCurl(self::URL_INCIDENT, 'POST');
		$json_data = $this->getSearchRequestObject($data, self::DATACONCEPT_INCIDENT);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
		
		// EXECUTE CURL
		$incidents_raw = curl_exec($ch);
		$incidents = json_decode($incidents_raw);
		
		// CHECK FOR ERRORS
		return $this->getSearchResults($incidents, $incidents->Incidents);
		//return var_dump($incidents->Incidents[0]);
	}
	
	public function createIncident($data, $ticketId) {
		return $this->executeTicket($data, self::URL_INCIDENT, self::DATACONCEPT_INCIDENT, self::TRANSACTION_TYPE_INSERT, $ticketId);
	}
	
	public function updateIncident($data) {
		return $this->executeTicket($data, self::URL_INCIDENT, self::DATACONCEPT_INCIDENT, self::TRANSACTION_TYPE_UPDATE);
	}
	
	public function closeIncident($data) {
		return $this->executeTicket($data, self::URL_INCIDENT, self::DATACONCEPT_INCIDENT, self::TRANSACTION_TYPE_CLOSE);
	}
	
	private function getIncidentRequestObject($data, $transactionType) {
		// GET MESSAGE HEADER
		$post = $this->getMessageHeader(self::DATACONCEPT_INCIDENT, $transactionType, $data['customer']); 
		
		// BUILD INSERT OBJECT
		$post->Incidents = array();
		
		/*** REQUIRED ***/
		$incident = new stdClass();
		if ($transactionType !== self::TRANSACTION_TYPE_CLOSE) {
			$incident->Name = $data['name'];
			$incident->Description = $data['description'];
			$incident->ImpactScope = 0;
			$incident->IncidentCategory = "incident";
			$incident->IncidentSubCategory = "failure";
			$incident->IncidentSubArea = "system down";
			$incident->IncidentStatus = 4;
			$incident->Urgency = 0;
			$incident->AssignmentGroupName = "MSCUST-A GSD";
			$incident->ServiceLine = "enterprise cloud services";
			$incident->ServiceType = "ecs vm management";
			$incident->ServiceArea = "ecs linux vm management";
			$incident->TicketSource = "ECSAIR";

			// JournalUpdates
			$incident->JournalUpdates = new stdClass();
			$incident->JournalUpdates->Text = "Created via RWS services";

			// IncidentLocation
			$incident->IncidentLocation = new stdClass();
			$incident->IncidentLocation->Name = "MSCUST-A HQ";

			// Contacts
			$incident->Contacts = array();

			// Contacts[0] = RequestedBy
			$incident->Contacts[0] = new stdClass();
			$incident->Contacts[0]->ITProcessContactRole = 0;
			$incident->Contacts[0]->ITProcessContactPerson = new stdClass();
			$incident->Contacts[0]->ITProcessContactPerson->Name = "BASICTEST01@MSCUST-A.COM";

			// Contacts[1] = RequestedFor
			$incident->Contacts[1] = new stdClass();
			$incident->Contacts[1]->ITProcessContactRole = 1;
			$incident->Contacts[1]->ITProcessContactPerson = new stdClass();
			$incident->Contacts[1]->ITProcessContactPerson->Name = "BASICTEST01@MSCUST-A.COM";

			/*** OPTIONAL ***/
		}

		// ID
		if ($transactionType !== self::TRANSACTION_TYPE_INSERT) {
			$incident->IncidentId = $data['id'];
		}
		
		// CLOSE
		if ($transactionType === self::TRANSACTION_TYPE_CLOSE) {
			$incident->CompletionCode = $data['CompletionCode'];
			$incident->Solution = $data['Solution'];
		}

		// RETURN
		$post->Incidents[0] = $incident;
		return json_encode($post);
	}
	
	/********** OTHER FUNCTIONS ************/
	
	private function executeTicket($data, $url, $dataConcept, $transactionType, $ticketId) {
		// GET JSON BODY
		switch ($dataConcept) {
			case self::DATACONCEPT_CHANGE:
				$json_data = $this->getChangeRequestObject($data, $transactionType);
				break;
			case self::DATACONCEPT_INCIDENT:
				$json_data = $this->getIncidentRequestObject($data, $transactionType);
				break;
			case self::DATACONCEPT_WORKORDER:
				$json_data = $this->getWorkOrderRequestObject($data, $transactionType);
				break;
		}
		
		// TEMP: ECHO JSON DATA
		echo 'JSON_DATA: 
' . $json_data . '
';
		
		// EXECUTE CURL
		$ch = $this->getCurl($url, 'POST');
		curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
		$result_raw = curl_exec($ch);
		$result = json_decode($result_raw);
		
		// CHECK FOR ERRORS
		switch ($dataConcept) {
			case self::DATACONCEPT_CHANGE:
				$id = $result->Changes[0]->ChangeId;
				break;
			case self::DATACONCEPT_INCIDENT:
				$id = $result->Incidents[0]->IncidentId;
				break;
			case self::DATACONCEPT_WORKORDER:
				$id = $result->WorkOrders[0]->WorkOrderId;
				break;
		}
		$success = $result->MessageHeader->MessageResult->ReturnMessage; // "SUCCESS" or "FAILURE"
		echo 'success = ' . $success . '
';
		echo 'id = ' . $id . '
';
		if ($id && $success === "SUCCESS") {
			if ($ticketId && $transactionType === self::TRANSACTION_TYPE_INSERT) {
				// STORE ID
				$data2 = array(
					"ticket_id" => $ticketId,
					"value" => $id,
					"name" => "id"
				);
				$stmt = $this->db->insert('SM9_TICKET_DATA', $data2);
				// UPDATE RFC
				$data3 = array(
					"rfc" => $id . " (" . $data['status'] . ")"
				);
				$where = array("
					service_request_actions_id = ?" => $data['task_id']
				);
				$stmt = $this->db->update('SERVICE_REQUEST_ACTIONS', $data3, $where);
			}
			return array(
				"id" => $id,
				"message" => "SUCCESS! Ticket $id affected."
			);
		} else if ($success) {
			$message = $success . ": ";
			$errors = $result->MessageHeader->MessageResult->ErrorDetails; // array: [0]->ErrorMessage
			if ($errors) {
				foreach ($errors as $error) {
					$message .= $error->ErrorMessage . "... ";
				}
			}
			// STORE ERROR
			if (!$ticketId && $data['id']) $ticketId = $this->getTicketIdBySM9Id($data['id']);
			if ($ticketId) {
				$data2 = array(
					"ticket_id" => $ticketId,
					"value" => $message,
					"name" => "error"
				);
				$stmt = $this->db->insert('SM9_TICKET_DATA', $data2);
			}
			echo 'message = ' . $message . ' 
';
			return $message;
		} else {
			return $result_raw;
		}
	}
	
	public function getChangeTicketById($id) {
		$data['Field'] = "ChangeId";
		$data['Operator'] = "0";
		$data['Value'] = $id;
		$data['GroupBy'] = "0";
		$data['customer'] = "HP ECS EIT 2";
		return $this->searchChanges($data);
	}
		
	/**
	 * @param type $id The SM9 id (i.e. "E-IM000035345")
	 */
	private function getTicketIdBySM9Id($id) {
		$select = $this->db->select()
			->from("SM9_TICKET_DATA", array("ticket_id"))
			->where("name = ?", "id")
			->where("value = ?", $id)
		;
		$stmt = $this->db->query($select);
		$ticket = $stmt->fetch();
		return $ticket['ticket_id'];
	}
	
	private function formatData($data) {
		$newData = array();
		foreach ($data as $item) {
			$newData[$item['name']] = $item['value'];
		}
		return $newData;
	}
	
	public function getTicketData($ticketId) {
		$select = $this->db->select()
			->from("SM9_TICKET_DATA", array("name", "value"))
			->where("ticket_id = ?", array($ticketId))
		;
		$stmt = $this->db->query($select);
		return $this->formatData($stmt->fetchAll());
	}
	
	public function getTicketByTask($taskId, $dataConcept=NULL) {
		$select = $this->db->select()
			->from("SM9_TICKETS")
			->where("task_id = ?", array($taskId))
			->where("enabled = 1")
		;
		if ($dataConcept) {
			$select->where("data_concept = ?", array($dataConcept));
		}
		$stmt = $this->db->query($select);
		$ticket = $stmt->fetch();
		
		if ($ticket && $ticket['ticket_id']) {
			$ticket['data'] = $this->getTicketData($ticket['ticket_id']);
			return $ticket;
		}
		return false;
	}
	
	public function createTicket($taskId) {
		// get ticket info and data
		$ticket = $this->getTicketByTask($taskId);
		$result = "ERROR: Invalid data concept '" . $ticket['data_concept'] . "' for task ID " . $taskId;
		switch ($ticket['data_concept']) {
			case self::DATACONCEPT_CHANGE:
				$result = $this->createChange($ticket['data'], $ticket['ticket_id']);
				break;
			case self::DATACONCEPT_INCIDENT:
				$result = $this->createIncident($ticket['data'], $ticket['ticket_id']);
				break;
			case self::DATACONCEPT_WORKORDER:
				$result = $this->createWorkOrder($ticket['data'], $ticket['ticket_id']);
				break;
		}
		// if success, add attachments
		if (gettype($result) === 'array' && $result['id']) {
			$srModel = new Application_Model_ServiceRequests();
			$attachments = $srModel->getTaskAttachments($taskId);
			foreach ($attachments as $attachment) {
				$x = $this->addAttachment($result['id'], $ticket['data'], $attachment);
			}
		}
		return $result;
	}
	
	/**
	 * insert multiple rows into TEMPLATE or TICKET, each row represents one form field
	 * @param int $id template_id if $isTemplate=TRUE, ticket_id if FALSE
	 * @param array $params
	 * @param boolean $isTemplate TRUE if TEMPLATE, FALSE if TICKET
	 */
	private function addData($id, $params, $isTemplate) {
		$table = ($isTemplate) ? "SM9_TEMPLATE_DATA" : "SM9_TICKET_DATA";
		$key = ($isTemplate) ? "template_id" : "ticket_id";
		$comma = false;
		$query = "INSERT INTO $table ($key, name, value) VALUES ";
		foreach ($params as $name => $value) {
			if (strlen($value) > 0 && $name != 'action' && $name != 'module' && $name != 'controller') {
				if ($comma) {
					$query .= ',';
				} else {
					$comma = true;
				}
				$query .= "('$id','$name','$value')";
			}
		}
		$stmt = $this->db->query($query);
	}
	
	public function deleteTemplate($templateId) {
		// DELETE TEMPLATE AND DATA
		$x = $this->db->delete('SM9_TEMPLATES', 'template_id = ?', array($templateId));
		$y = $this->db->delete('SM9_TEMPLATE_DATA', 'template_id = ?', array($templateId));
	}
	
	public function getTemplate($templateId) {
		$select = $this->db->select()
			->from("SM9_TEMPLATES")
			->where("template_id = ?", array($templateId))
		;
		$stmt = $this->db->query($select);
		return $stmt->fetch();
	}
	
	public function getTemplates($dataConcept, $catalogId=NULL, $regionId=NULL, $customerId=NULL) {
		$select = $this->db->select()
			->from(array('T' => 'SM9_TEMPLATES'), array("template_id","name"))
			->where("T.data_concept = ?", array($dataConcept))
			->where("T.enabled = 1")
			->order("T.name")
		;
		if ($catalogId) {
			$select->where("T.catalog_id = ? OR T.catalog_id IS NULL OR T.catalog_id = 0", array($catalogId));
		}
		if ($regionId) {
			$select->where("T.region_id = ? OR T.region_id IS NULL OR T.region_id = 0", array($regionId));
		}
		if ($customerId) {
			$select->where("T.customer_id = ? OR T.customer_id IS NULL OR T.customer_id = 0", array($customerId));
		}
		$stmt = $this->db->query($select);
		return $stmt->fetchAll();
	}

	public function getTemplateData($templateId) {
		$select = $this->db->select()
			->from("SM9_TEMPLATE_DATA")
			->where("template_id = ?", array($templateId))
		;
		$stmt = $this->db->query($select);
		$result = $stmt->fetchAll();
		return $this->formatData($result);
	}
	
	private function updateTemplate($params, $templateId) {
		// UPDATE TEMPLATE
		$data = array(
			"updated_on" => new Zend_Db_Expr('NOW()')
		);
		$where = array(
			"template_id = ?" => $templateId
		);
		$stmt = $this->db->update('SM9_TEMPLATES', $data, $where);

		// DELETE EXISTING TEMPLATE DATA
		$del = $this->db->delete('SM9_TEMPLATE_DATA', 'template_id = ' . $templateId);

		// ADD TEMPLATE DATA
		$this->addData($templateId, $params, true);

		return array(
			"message" => "Template '" . $params['template_name'] . "' successfully updated.",
			"template_id" => $templateId
		);
	}

	public function saveTicket($params) {
		// CREATE NEW TICKET
		$data = array(
			"task_id" => $params['task_id'],
			"data_concept" => $params['data_concept']
		);
		$stmt = $this->db->insert('SM9_TICKETS', $data);
		
		// GET LAST ID
		$ticketId = $this->db->lastInsertId();					

		// ADD TICKET DATA
		$this->addData($ticketId, $params, false);
		
		return "New ticket for task #'" . $params['task_id'] . "' successfully created.";
	}
	
	private function createTemplate($params) {
		// CREATE NEW TEMPLATE
		$data = array(
			"name" => $params['template_name'],
			"data_concept" => $params['data_concept']
		);
		$stmt = $this->db->insert('SM9_TEMPLATES', $data);
		
		// GET LAST ID
		$templateId = $this->db->lastInsertId();					

		// ADD TEMPLATE DATA
		$this->addData($templateId, $params, true);
		
		return array(
			"message" => "New template '" . $data['name'] . "' successfully created.",
			"template_id" => $templateId
		);
	}
	
	public function saveTemplate($params, $templateId) {
		if ($templateId) {
			$result = $this->updateTemplate($params, $templateId);
		} else {
			$result = $this->createTemplate($params);
		}
		return $result;
	}
}
?>