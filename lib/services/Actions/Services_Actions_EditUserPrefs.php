<?php

class Services_Actions_EdtUserPrefs {
	private $_svcUserFilter;
	private $_svcUserRecord;
	private $_svcUserAuth;
	private $_spotSec;

	function __construct(Services_User_Record $svcUserRecord, Services_User_Filter $svcUserFilter,Services_User_Authentication $svcUserAuth, SpotSecurity $spotSec) {
		$this->_svcUserFilter = $svcUserFilter;
		$this->_svcUserRecord = $svcUserRecord;
		$this->_svcUserAuth = $svcUserAuth;
		$this->_spotSec = $spotSec;
	} # ctor


	function editUserPref(array $editUserPrefsForm, array $spotUser) {
		/*
		 * We want the annymous' users account so we can use this users' preferences as a
		 * template. This makes sure all properties are atleast set.
		 */
		$anonUser = $this->_svcUserRecord->getUser(SPOTWEB_ANONYMOUS_USERID);

		/*
		 * We have a few dummy preferenes -- these are submitted like a checkbox for example
		 * but in reality do something completely different.
		 *
		 * Because we use cleanseUserPreferences() those dummies will not end up in the database
		 */
		if (isset($editUserPrefsForm['_dummy_prevent_porn'])) {
			$this->_svcUserFilter->setEroticIndexFilter($spotUser['userid']);
		} else {
			$this->_svcUserFilter->removeIndexFilter($spotUser['userid']);
		} # if

		# Save the current' user preferences because we need them before cleansing 
		$savePrefs = $spotUser['prefs'];
		$spotUser['prefs'] = $this->_svcUserRecord->cleanseUserPreferences($editUserPrefsForm, 
									$anonUser['prefs'],
									$this->_tplHelper->getTemplatePreferences());

		# Validate all preferences
		$result = $this->_svcUserRecord->validateUserPreferences($spotUser['prefs'], $savePrefs);
		$spotUser['prefs'] = $result->getData('prefs');

		# Make sure user has permission to select this template
		if ($spotUser['prefs']['normal_template'] != $savePrefs['normal_template']) {
			$this->_spotSec->fatalPermCheck(SpotSecurity::spotsec_select_template, $spotUser['prefs']['normal_template']);
		} # if

		if ($spotUser['prefs']['mobile_template'] != $savePrefs['mobile_template']) {
			$this->_spotSec->fatalPermCheck(SpotSecurity::spotsec_select_template, $spotUser['prefs']['mobile_template']);
		} # if

		if ($spotUser['prefs']['tablet_template'] != $savePrefs['tablet_template']) {
			$this->_spotSec->fatalPermCheck(SpotSecurity::spotsec_select_template, $spotUser['prefs']['tablet_template']);
		} # if

		if ($result->isSuccess()) {
			# Make sure an NZB file was provided
			if (isset($_FILES['edituserprefsform'])) {
				$uploadError = $_FILES['edituserprefsform']['error']['avatar'];
				
				/*
				 * Give a proper error if the file is too large, because changeAvatar() wont see
				 * these errors so they cannot provide the error
				 */
				if (($uploadError == UPLOAD_ERR_FORM_SIZE) || ($uploadError == UPLOAD_ERR_INI_SIZE)) {
					$result->addError(_("Uploaded file is too large"));
				}  # if 
				
				if ($uploadError == UPLOAD_ERR_OK) {
					$avatarResult  = $this->_svcUserRecord->changeAvatar(
													$spotUser['userid'], 
													file_get_contents($_FILES['edituserprefsform']['tmp_name']['avatar']));

					/*
					 * Merge the result of the avatar update to our
					 * total result
					 */
					$result->mergeResult($avatarResult);
				} # if
			} # if
		} # if

		if ($result->isSuccess()) { 
			# and actually update the user in the database
			$this->_svcUserRecord->setUser($spotUser);
		} # if

		/*
		 * We have the register Spotweb with the notification providers (growl, prowl, etc) atleast once. 
		 * The safes option is to just do this wih each preferences submit. But first we create a fake
		 * session for this user.
		 */
		$fakeSession = $this->_svcUserAuth->createNewSession($spotUser['userid']);
		$fakeSession['security'] = new SpotSecurity($this->_db, $this->_settings, $fakeSession['user'], '');

		$spotsNotifications = new SpotNotifications($this->_db, $this->_settings, $fakeSession);
		$spotsNotifications->register();
	} # editUserPref

} # Services_Actions_EdtUserPrefs