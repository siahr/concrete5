<?
defined('C5_EXECUTE') or die(_("Access Denied."));
class RegisterController extends Controller {

	public $helpers = array('form', 'html');
	
	public function __construct() {
		if(!ENABLE_REGISTRATION) {
			$this->render("/page_not_found");
		}
		parent::__construct();
		Loader::model('user_attributes');

		$u = new User();
		$this->set('u', $u);
		
		if (USER_REGISTRATION_WITH_EMAIL_ADDRESS) {
			$this->set('displayUserName', false);
		} else {
			$this->set('displayUserName', true);
		}
	}
	
	public function forward($cID) {
		$this->set('rcID', $cID);
	}
	
	public function do_register() {
	
		$registerData['success']=0;
		
		$e = Loader::helper('validation/error');
		$ip = Loader::helper('validation/ip');		
		$txt = Loader::helper('text');
		$vals = Loader::helper('validation/strings');
		$valc = Loader::helper('concrete/validation');

		if (USER_REGISTRATION_WITH_EMAIL_ADDRESS == true) {
			$_POST['uName'] = $_POST['uEmail'];
		}
		
		$username = $_POST['uName'];
		$password = $_POST['uPassword'];
		$passwordConfirm = $_POST['uPasswordConfirm'];
		
		if (!$ip->check()) {
			$e->add($ip->getErrorMessage());
		}		
		
		$captcha = Loader::helper('validation/captcha');
	   if (!$captcha->check()) {
	      $e->add(t("Incorrect image validation code. Please check the image and re-enter the letters or numbers as necessary."));
	     }
	
		if (!$vals->email($_POST['uEmail'])) {
			$e->add(t('Invalid email address provided.'));
		} else if (!$valc->isUniqueEmail($_POST['uEmail'])) {
			$e->add(t("The email address %s is already in use. Please choose another.", $_POST['uEmail']));
		}
		
		if (USER_REGISTRATION_WITH_EMAIL_ADDRESS == false) {
			if (strlen($username) < USER_USERNAME_MINIMUM) {
				$e->add(t('A username must be between at least %s characters long.', USER_USERNAME_MINIMUM));
			}
	
			if (strlen($username) > USER_USERNAME_MAXIMUM) {
				$e->add(t('A username cannot be more than %s characters long.', USER_USERNAME_MAXIMUM));
			}
	
			if (strlen($username) >= USER_USERNAME_MINIMUM && !$vals->alphanum($username)) {
				$e->add(t('A username may only contain letters or numbers.'));
			}
			if (!$valc->isUniqueUsername($username)) {
				$e->add(t("The username %s already exists. Please choose another", $username));
			}		
		}
		
		if ($username == USER_SUPER) {
			$e->add(t('Invalid Username'));
		}
		
		if ((strlen($password) < USER_PASSWORD_MINIMUM) || (strlen($password) > USER_PASSWORD_MAXIMUM)) {
			$e->add(t('A password must be between %s and %s characters', USER_PASSWORD_MINIMUM, USER_PASSWORD_MAXIMUM));
		}
			
		if (strlen($password) >= USER_PASSWORD_MINIMUM && !$valc->password($password)) {
			$e->add(t('A password may not contain ", \', >, <, or any spaces.'));
		}

		if ($password) {
			if ($password != $passwordConfirm) {
				$e->add(t('The two passwords provided do not match.'));
			}
		}
	
		$invalidFields = UserAttributeKey::validateSubmittedRequest();
		foreach($invalidFields as $field) {
			$e->add(t("The field %s is required.", $field));
		}

		if (!$e->has()) {
			
			// do the registration
			$data = $_POST;
			$data['uName'] = $username;
			$data['uPassword'] = $password;
			$data['uPasswordConfirm'] = $passwordConfirm;

			$process = UserInfo::register($data);
			if (is_object($process)) {

				$process->updateUserAttributes($data);
				
				// now we log the user in

				$u = new User($_POST['uName'], $_POST['uPassword']);
				// if this is successful, uID is loaded into session for this user
				
				$rcID = $this->post('rcID');
				$nh = Loader::helper('validation/numbers');
				if (!$nh->integer($rcID)) {
					$rcID = 0;
				}
				if(defined('USER_REGISTRATION_APPROVAL_REQUIRED') && USER_REGISTRATION_APPROVAL_REQUIRED) {
					$ui = UserInfo::getByID($u->getUserID());
					$ui->deactivate();
					//$this->redirect('/register', 'register_pending', $rcID);
					$redirectMethod='register_pending';
					$registerData['msg']=$this->getRegisterPendingMsg();
					
				// now we check whether we need to validate this user's email address
				} elseif (defined("USER_VALIDATE_EMAIL")) {
					if (USER_VALIDATE_EMAIL > 0) {
						$uHash = $process->setupValidation();
						
						$mh = Loader::helper('mail');
						$mh->addParameter('uEmail', $_POST['uEmail']);
						$mh->addParameter('uHash', $uHash);
						$mh->to($_POST['uEmail']);
						$mh->load('validate_user_email');
						$mh->sendMail();

						//$this->redirect('/register', 'register_success_validate', $rcID);
						$redirectMethod='register_success_validate';
						$registerData['msg']= join('<br><br>',$this->getRegisterSuccessValidateMsgs());
					}
				}
				
				if (!$u->isError()) {
					//$this->redirect('/register', 'register_success', $rcID);
					if(!$redirectMethod){
						$redirectMethod='register_success';	
						$registerData['msg']=$this->getRegisterSuccessMsg();
					}
					$registerData['uID']=intval($u->uID);		
					
					if($_REQUEST['remote'] && intval($_REQUEST['timestamp'])){ 
						$registerData['auth_token'] = 	UserInfo::generateAuthToken( $u->getUserName(), intval($_REQUEST['timestamp']) );
						//is this user in a support group? 
						foreach( RemoteMarketplaceHelper::getSupportGroups() as $group){
							if($u->inGroup($group)){
								$inValidSupportGroup=1;
								break;
							}
						}
						$registerData['in_support_group'] = intval($inValidSupportGroup);						
					}
				}
				
				$registerData['success']=1;
				
				if($_REQUEST['format']!='JSON')
					$this->redirect('/register', $redirectMethod, $rcID);				
			}
		} else {
			$ip->logSignupRequest();
			if ($ip->signupRequestThreshholdReached()) {
				$ip->createIPBan();
			}		
			$this->set('error', $e);
			$registerData['errors'] = $e->getList();
		}
		
		if( $_REQUEST['format']=='JSON' ){
			$jsonHelper=Loader::helper('json'); 
			echo $jsonHelper->encode($registerData);
			die;
		}		
	}
	
	public function register_success_validate($rcID = 0) {
		$this->set('rcID', $rcID);
		$this->set('success', 'validate');
		$this->set('successMsg', $this->getRegisterSuccessValidateMsgs() );
	}
	
	public function register_success($rcID = 0) {
		$this->set('rcID', $rcID);
		$this->set('success', 'registered');
		$this->set('successMsg', $this->getRegisterSuccessMsg() );
	}

	public function register_pending() {
		$this->set('rcID', $rcID);
		$this->set('success', 'pending');
		$this->set('successMsg', $this->getRegisterPendingMsg() );
	}
	
	public function getRegisterSuccessMsg(){
		return t('Your account has been created, and you are now logged in.');
	}
	
	public function getRegisterSuccessValidateMsgs(){
		$msgs=array();
		$msgs[]= t('You are registered but you need to validate your email address. Some or all functionality on this site will be limited until you do so.');
		$msgs[]= t('An email has been sent to your email address. Click on the URL contained in the email to validate your email address.');
		return $msgs;
	}
	
	public function getRegisterPendingMsg(){
		return t('You are registered but a site administrator must review your account, you will not be able to login until your account has been approved.');
	}	
}

?>
