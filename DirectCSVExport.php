<?php
/**
 * DirectCSVExport plugin for LimeSurvey
 *  - Provides a URL that in combination with a secret key will allow the download of the complete data from a survey including token/participant data
 *  - The CSV is formatted in a way ideal for analytics.
 *  - Ideal for running scripted imports to systems like Microsoft Power BI without the need for direct database access
 *
 * @author Adam Zammit <adam@acspri.org.au>
 * @copyright 2020 ACSPRI <https://www.acspri.org.au>
 * @license GPL v3
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 */

class DirectCSVExport extends PluginBase {

    protected $storage = 'DbStorage';
    static protected $description = 'Direct CSV Export';
    static protected $name = 'DirectCSVExport';

    public function init() {
        $this->subscribe('beforeSurveySettings');
        $this->subscribe('newSurveySettings');
        $this->subscribe('newDirectRequest'); //for call to API
        $this->subscribe('newUnsecureRequest','newDirectRequest'); //for call to API
    }


    protected $settings = array(
        'bUse' => array (
            'type' => 'select',
            'options' => array (
                0 => 'No',
                1 => 'Yes'
            ),
            'default' => 1,
            'label' => 'Use for every survey on this installation by default?',
            'help' => 'Overwritable in each Survey setting'
        ),

    );



    public function newDirectRequest()
    {
        $oEvent = $this->getEvent();

        if ($oEvent->get('target') != $this->getName())
            return;


        if ((Yii::app()->request->getQuery('surveyId') == NULL) || (Yii::app()->request->getQuery('APIKey') == NULL))
            return;


        $sSurveyId = Yii::app()->request->getQuery('surveyId');
        $sAPIKey = Yii::app()->request->getQuery('APIKey');

        if (!empty($sSurveyId)) {
            $iSurveyId = intval($sSurveyId);
            $surveyidExists = Survey::model()->findByPk($iSurveyId);
        }

        if (!isset($surveyidExists)) {
            die("Survey does not exist");
        }

        if (!empty($sAPIKey) && !(($this->get('bUse','Survey',$iSurveyId)==0)||(($this->get('bUse','Survey',$iSurveyId)==2) && ($this->get('bUse',null,null,$this->settings['bUse'])==0)))) {
            //if enabled for this survey
            if ($sAPIKey == $this->get ( 'sAPIKey', 'Survey', $iSurveyId ) ) {//APIKey matches
                Yii::import('application.helpers.admin.export.FormattingOptions', true);
                Yii::import('application.helpers.admin.exportresults_helper', true);
                Yii::import('application.helpers.viewHelper', true);
                $survey = Survey::model()->findByPk($iSurveyId);
                if (!tableExists($survey->responsesTableName)) {
                    die('No Data, survey table does not exist.');
                }
                if (!($maxId = SurveyDynamic::model($iSurveyId)->getMaxId())) {
                    die('No Data, could not get max id.');
                }
                $oFormattingOptions = new FormattingOptions();
                $oFormattingOptions->responseMinRecord = 1;
                $oFormattingOptions->responseMaxRecord = $maxId;
		$aFields = array_keys(createFieldMap($survey, 'full', true, false, $survey->language));
		$aTokenFields = [];
		if ($survey->hasTokensTable) {
			$aTokenFields = array('tid','participant_id','firstname','lastname','email','emailstatus','language','blacklisted','sent','remindersent','remindercount','completed','usesleft','validfrom','validuntil','mpid');
	                foreach($survey->tokenAttributes as $key => $value) {
	                    $aTokenFields[] = $key;
	                }
		}
                $oFormattingOptions->selectedColumns = array_merge($aFields,$aTokenFields);
                $oFormattingOptions->responseCompletionState = 'all';
                $oFormattingOptions->headingFormat = 'full';
                $oFormattingOptions->answerFormat = 'long';
                $oFormattingOptions->csvFieldSeparator = ',';
                $oFormattingOptions->output = 'display';
                $oExport = new ExportSurveyResultsService();
                $sTempFile = $oExport->exportResponses($iSurveyId, $survey->language, 'csv', $oFormattingOptions, '');
            } else {

                die("Unavailable");
            }

        } else {
            die("Unavailable");
        }
    }


    /**
     * Survey level settings - require the setting of an API key before displaying the API URL
     */
    public function beforeSurveySettings()
    {
        $oEvent = $this->event;
        $apiKey = $this->get ( 'sAPIKey', 'Survey', $oEvent->get ( 'survey' ) );
        $message = "Set an API key and save these settings before you can access the API";
        if (!empty($apiKey)) {
            $message =  Yii::app()->createAbsoluteUrl('plugins/direct', array('plugin' => "DirectCSVExport", 'surveyId' => $oEvent->get('survey'), "APIKey" =>$apiKey ));
        }
        $aSets = array (
            'bUse' => array (
                'type' => 'select',
                'label' => 'Enable the Direct CSV Export API for this survey?',
                'options' => array (
                    0 => 'No',
                    1 => 'Yes',
                    2 => 'Use site settings (default)'
                ),
                'default' => 2,
                'help' => 'Leave default to use global setting',
                'current' => $this->get ( 'bUse', 'Survey', $oEvent->get ( 'survey' ) )
            ),
            'sAPIKey' => array (
                'type' => 'text',
                'label' => 'The API Key',
                'help' => 'Treat this string like a password',
                'current' => $this->get('sAPIKey', 'Survey', $oEvent->get('survey'),$this->get('sAPIKey',null,null,str_replace(array('~', '_'), array('a', 'z'), Yii::app()->securityManager->generateRandomString(64)))),
            ),
            'sInfo' => array (
                'type' => 'info',
                'label' => 'The URL to access this API',
                'help' =>  $message
            ),

        );

        $aSettings = array(
            'name' => get_class ( $this ),
            'settings' => $aSets,
        );
        $oEvent->set("surveysettings.{$this->id}", $aSettings);

    }

    /**
     * Save the settings
     */
    public function newSurveySettings()
    {
        $event = $this->event;
        foreach ($event->get('settings') as $name => $value)
        {
            $default=$event->get($name,null,null,isset($this->settings[$name]['default'])?$this->settings[$name]['default']:NULL);
            $this->set($name, $value, 'Survey', $event->get('survey'),$default);
        }
    }

}
