<?php

use LimeSurvey\Models\Services\FilterImportedResources;

class SurveyAdministrationController extends LSBaseController
{

    /**
     * It's import to have the accessRules set (security issue).
     * Only logged in users should have access to actions. All other permissions
     * should be checked in the action itself.
     *
     * @return array
     */
    public function accessRules()
    {
        return [
            [
                'allow',
                'actions' => [],
                'users'   => ['*'], //everybody
            ],
            [
                'allow',
                'actions' => ['view','delete', 'index', 'insert', 'listsurveys', 'newSurvey',
                    'regenerateQuestionCode', 'renderItemsSelected', 'applythemeoptions',
                    'changeMultipleSurveyGroup', 'changeMultipleTheme', 'deleteMultiple',
                    'fakebrowser', 'getAjaxMenuArray', 'getAjaxQuestionGroupArray','getCurrentEditorValues',
                    'getDataSecTextSettings', 'getDateFormatOptions', ''],
                'users'   => ['@'], //only login users
            ],
            ['deny'], //always deny all actions not mentioned above
        ];
    }

    /**
     * This part comes from _renderWrappedTemplate
     *
     * @param string $view
     * @return bool
     */
    protected function beforeRender($view)
    {
        if (!empty($this->aData['surveyid'])) {
            $this->aData['oSurvey'] = $this->aData['oSurvey'] ?? Survey::model()->findByPk($this->aData['surveyid']);

            // Needed to evaluate EM expressions in question summary
            // See bug #11845
            LimeExpressionManager::SetSurveyId($this->aData['surveyid']);
            LimeExpressionManager::StartProcessingPage(false, true);

            $this->layout = 'layout_questioneditor';
        } else {
            $this->layout = 'main';
        }

        return parent::beforeRender($view);
    }

    /**
     * Load complete view of survey properties and actions specified by $iSurveyID
     *
     * @param mixed $iSurveyID Given Survey ID
     * @param mixed $gid       Given Group ID
     * @param mixed $qid       Given Question ID
     *
     * @return void
     *
     * @access public
     */
    public function actionView($iSurveyID, $gid = null, $qid = null)
    {
        $beforeSurveyAdminView = new PluginEvent('beforeSurveyAdminView');
        $beforeSurveyAdminView->set('surveyId', $iSurveyID);
        App()->getPluginManager()->dispatchEvent($beforeSurveyAdminView);

        // We load the panel packages for quick actions
        $iSurveyID = sanitize_int($iSurveyID);
        $survey    = Survey::model()->findByPk($iSurveyID);
        $baselang  = $survey->language;

        $aData = array('aAdditionalLanguages' => $survey->additionalLanguages);

        // Reinit LEMlang and LEMsid: ensure LEMlang are set to default lang, surveyid are set to this survey id
        // Ensure Last GetLastPrettyPrintExpression get info from this sid and default lang
        LimeExpressionManager::SetEMLanguage($baselang);
        LimeExpressionManager::SetSurveyId($iSurveyID);
        LimeExpressionManager::StartProcessingPage(false, true);

        $aData['title_bar']['title'] =
            $survey->currentLanguageSettings->surveyls_title." (".gT("ID").":".$iSurveyID.")";
        $aData['surveyid'] = $iSurveyID;
        $aData['sid'] = $iSurveyID; //frontend need this to render topbar for the view

        //NOTE this is set because ONLY this leads to render the view surveySummary_view, no need to use in anymore
       // $aData['display']['surveysummary'] = true;

        // Last survey visited
        $setting_entry = 'last_survey_'. App()->user->getId();
        SettingGlobal::setSetting($setting_entry, $iSurveyID);

        $aData['surveybar']['buttons']['view'] = true;
        $aData['surveybar']['returnbutton']['url'] = $this->createUrl("admin/survey/sa/listsurveys");
        $aData['surveybar']['returnbutton']['text'] = gT('Return to survey list');
        $aData['sidemenu']["survey_menu"] = true;

        // We get the last question visited by user for this survey
        $setting_entry = 'last_question_'.App()->user->getId().'_'.$iSurveyID;
        // TODO: getGlobalSetting() DEPRECATED
        $lastquestion = getGlobalSetting($setting_entry);
        $setting_entry = 'last_question_'.App()->user->getId().'_'.$iSurveyID.'_gid';

        // TODO: getGlobalSetting() DEPRECATED
        $lastquestiongroup = getGlobalSetting($setting_entry);

        if ($lastquestion != null && $lastquestiongroup != null) {
            $aData['showLastQuestion'] = true;
            $iQid = $lastquestion;
            $iGid = $lastquestiongroup;
            $qrrow = Question::model()->findByAttributes(array('qid' => $iQid, 'gid' => $iGid, 'sid' => $iSurveyID));

            $aData['last_question_name'] = $qrrow['title'];
            if (!empty($qrrow->questionl10ns[$baselang]['question'])) {
                $aData['last_question_name'] .= ' : '.$qrrow->questionl10ns[$baselang]['question'];
            }

            $aData['last_question_link'] =
                $this->createUrl("questionEditor/view/surveyid/$iSurveyID/gid/$iGid/qid/$iQid");
        } else {
            $aData['showLastQuestion'] = false;
        }
        $aData['templateapiversion'] = Template::model()->getTemplateConfiguration(null, $iSurveyID)->getApiVersion();

        $user = User::model()->findByPk(App()->session['loginID']);
        $aData['owner'] = $user->attributes;

        if ((empty($aData['display']['menu_bars']['surveysummary']) || !is_string($aData['display']['menu_bars']['surveysummary'])) && !empty($aData['gid'])) {
            $aData['display']['menu_bars']['surveysummary'] = 'viewgroup';
        }

        $this->surveysummary($aData);

        $this->aData = $aData;
        $this->render('sidebody', [
            //'content' => $content,
            'sideMenuOpen' => true
        ]);
    }

    /**
     * Loads list of surveys and its few quick properties.
     *
     * todo: this could be a direct call to actionListsurveys
     *
     * @access public
     * @return void
     */
    public function actionIndex()
    {
        $this->redirect(
            array(
                'surveyAdministration/listsurveys'
            )
        );
    }

    /**
     * List Surveys.
     *
     * @return void
     */
    public function actionListsurveys()
    {
        Yii::app()->loadHelper('surveytranslator');
        $aData = array();
        $aData['issuperadmin'] = false;

        if (Permission::model()->hasGlobalPermission('superadmin', 'read')) {
            $aData['issuperadmin'] = true;
        }
        $aData['model'] = new Survey('search');
        $aData['groupModel'] = new SurveysGroups('search');
        $aData['fullpagebar']['button']['newsurvey'] = true;

        $this->render('listSurveys_view', $aData);
    }

    /**
     * Delete multiple survey
     *
     * @return void
     * @throws CException
     */
    public function actionDeleteMultiple()
    {
        $aSurveys = json_decode(Yii::app()->request->getPost('sItems'));
        $aResults = array();
        foreach ($aSurveys as $iSurveyID) {
            if (Permission::model()->hasSurveyPermission($iSurveyID, 'survey', 'delete')) {
                $oSurvey                        = Survey::model()->findByPk($iSurveyID);
                $aResults[$iSurveyID]['title']  = $oSurvey->correct_relation_defaultlanguage->surveyls_title;
                $aResults[$iSurveyID]['result'] = Survey::model()->deleteSurvey($iSurveyID);
            }
        }
        $this->renderPartial(
            'ext.admin.survey.ListSurveysWidget.views.massive_actions._action_results',
            array(
                'aResults'     => $aResults,
                'successLabel' => gT('Deleted')
            )
        );
    }

    /**
     * Render selected items for massive action
     *
     * @return void
     */
    public function actionRenderItemsSelected()
    {
        $aSurveys = json_decode(Yii::app()->request->getPost('$oCheckedItems'));
        $aResults = [];
        $tableLabels= array(gT('Survey ID'),gT('Survey title') ,gT('Status'));
        foreach ($aSurveys as $iSurveyID) {
            if (!is_numeric($iSurveyID)) {
                continue;
            }
            if (Permission::model()->hasSurveyPermission($iSurveyID, 'survey', 'delete')) {
                $oSurvey                        = Survey::model()->findByPk($iSurveyID);
                $aResults[$iSurveyID]['title']  = $oSurvey->correct_relation_defaultlanguage->surveyls_title;
                $aResults[$iSurveyID]['result'] = 'selected';
            }
        }

        $this->renderPartial(
            'ext.admin.grid.MassiveActionsWidget.views._selected_items',
            array(
                'aResults'     => $aResults,
                'successLabel' => gT('Selected'),
                'tableLabels'  => $tableLabels
            )
        );
    }

    /**
     *
     * @param int    $iSurveyID  Given Survey ID
     * @param string $sSubAction Given Subaction
     *
     * @return void
     *
     * @todo Add TypeDoc.
     */
    public function actionRegenerateQuestionCodes($iSurveyID, $sSubAction)
    {
        if (!Permission::model()->hasSurveyPermission($iSurveyID, 'surveycontent', 'update')) {
            Yii::app()->setFlashMessage(gT("You do not have permission to access this page."), 'error');
            $this->redirect(array('surveyAdministration/view', 'surveyid'=>$iSurveyID));
        }
        $oSurvey = Survey::model()->findByPk($iSurveyID);
        if ($oSurvey->isActive) {
            Yii::app()->setFlashMessage(gT("You can't update question code for an active survey."), 'error');
            $this->redirect(array('surveyAdministration/view', 'surveyid'=>$iSurveyID));
        }
        //Automatically renumbers the "question codes" so that they follow
        //a methodical numbering method
        $iQuestionNumber = 1;
        $iGroupNumber    = 0;
        $iGroupSequence  = 0;
        $oQuestions      = Question::model()
            ->with(['group', 'questionl10ns'])
            ->findAll(
                array(
                    'select' => 't.qid,t.gid',
                    'condition' => "t.sid=:sid and questionl10ns.language=:language and parent_qid=0",
                    'order' => 'group.group_order, question_order',
                    'params' => array(':sid' => $iSurveyID, ':language' => $oSurvey->language)
                )
            );

        foreach ($oQuestions as $oQuestion) {
            if ($sSubAction == 'bygroup' && $iGroupNumber != $oQuestion->gid) {
                //If we're doing this by group, restart the numbering when the group number changes
                $iQuestionNumber = 1;
                $iGroupNumber    = $oQuestion->gid;
                $iGroupSequence++;
            }
            $sNewTitle = (($sSubAction == 'bygroup') ? ('G'.$iGroupSequence) : '')."Q".
                str_pad($iQuestionNumber, 5, "0", STR_PAD_LEFT);
            Question::model()->updateAll(array('title'=>$sNewTitle), 'qid=:qid', array(':qid'=>$oQuestion->qid));
            $iQuestionNumber++;
            $iGroupNumber = $oQuestion->gid;
        }
        Yii::app()->setFlashMessage(gT("Question codes were successfully regenerated."));
        LimeExpressionManager::SetDirtyFlag(); // so refreshes syntax highlighting
        $this->redirect(array('surveyAdministration/view/surveyid/'.$iSurveyID));
    }

    /**
     * This function prepares the view for a new survey
     *
     * @return void
     */
    public function actionNewSurvey()
    {
        if (!Permission::model()->hasGlobalPermission('surveys', 'create')) {
            Yii::app()->user->setFlash('error', gT("Access denied"));
            $this->redirect(Yii::app()->request->urlReferrer);
        }
        $survey = new Survey();
        // set 'inherit' values to survey attributes
        $survey->setToInherit();

        App()->getClientScript()->registerPackage('jquery-json');
        App()->getClientScript()->registerPackage('bootstrap-switch');
        Yii::app()->loadHelper('surveytranslator');
        Yii::app()->loadHelper('admin/htmleditor');

        $esrow = $this->fetchSurveyInfo('newsurvey');


        //$aViewUrls['output']  = PrepareEditorScript(false, $this->getController());
        $aData                = $this->generalTabNewSurvey();
        $aData                = array_merge($aData, $this->getGeneralTemplateData(0));
        $aData['esrow']       = $esrow;

        $aData['oSurvey'] = $survey;
        $aData['bShowAllOptions'] = true;
        $aData['bShowInherited'] = true;
        $oSurveyOptions = $survey;
        $oSurveyOptions->bShowRealOptionValues = false;
        $oSurveyOptions->setOptions();
        $aData['oSurveyOptions'] = $oSurveyOptions->oOptionLabels;

        $aData['optionsOnOff'] = array(
            'Y' => gT('On', 'unescaped'),
            'N' => gT('Off', 'unescaped'),
        );

        //Prepare the edition panes
        $aData['edittextdata']              = array_merge($aData, $this->getTextEditData($survey));
        $aData['datasecdata']               = array_merge($aData, $this->getDataSecurityEditData($survey));
        $aData['generalsettingsdata']       = array_merge($aData, $this->generalTabEditSurvey($survey));
        $aData['presentationsettingsdata']  = array_merge($aData, $this->tabPresentationNavigation($esrow));
        $aData['publicationsettingsdata']   = array_merge($aData, $this->tabPublicationAccess($survey));
        $aData['notificationsettingsdata']  = array_merge($aData, $this->tabNotificationDataManagement($esrow));
        $aData['tokensettingsdata']         = array_merge($aData, $this->tabTokens($esrow));

        $aViewUrls[] = 'newSurvey_view';

        $arrayed_data                                              = array();
        $arrayed_data['oSurvey']                                   = $survey;
        $arrayed_data['data']                                      = $aData;
        $arrayed_data['title_bar']['title']                        = gT('New survey');
        $arrayed_data['fullpagebar']['savebutton']['form']         = 'addnewsurvey';
        $arrayed_data['fullpagebar']['closebutton']['url']         = 'admin/index'; // Close button

        $aData['title_bar']['title']                        = gT('New survey');
        $aData['fullpagebar']['savebutton']['form']         = 'addnewsurvey';
        $aData['fullpagebar']['closebutton']['url']         = 'admin/index'; // Close button

        $this->aData = $aData;

        //this is not the layout
        $this->render('newSurvey_view', [
            'arrayed_data' => $arrayed_data
        ]);
    }

    /**
     * Saves the new survey after the creation screen is submitted
     *
     * @param int $iSurveyID The survey id to be used for the new survey.
     *                       If already taken a new random one will be used.
     *
     * @return string
     */
    public function actionInsert($iSurveyID = null)
    {
        if (Permission::model()->hasGlobalPermission('surveys', 'create')) {
            // Check if survey title was set
            if (Yii::app()->request->getPost('surveyls_title') == '') {
                $alertError = gT("Survey could not be created because it did not have a title");

                return Yii::app()->getController()->renderPartial(
                    '/admin/super/_renderJson',
                    array(
                        'data' => array(
                            'alertData' => $alertError,
                            'missingField' => 'surveyls_title'
                        )
                    ),
                    false,
                    false
                );
            }

            Yii::app()->loadHelper("surveytranslator");
            // If start date supplied convert it to the right format
            $aDateFormatData = getDateFormatData(Yii::app()->session['dateformat']);
            $sStartDate = Yii::app()->request->getPost('startdate');
            if (trim($sStartDate) != '') {
                Yii::import('application.libraries.Date_Time_Converter');
                $converter = new Date_Time_Converter($sStartDate, $aDateFormatData['phpdate'].' H:i:s');
                $sStartDate = $converter->convert("Y-m-d H:i:s");
            }

            // If expiry date supplied convert it to the right format
            $sExpiryDate = Yii::app()->request->getPost('expires');
            if (trim($sExpiryDate) != '') {
                Yii::import('application.libraries.Date_Time_Converter');
                $converter = new Date_Time_Converter($sExpiryDate, $aDateFormatData['phpdate'].' H:i:s');
                $sExpiryDate = $converter->convert("Y-m-d H:i:s");
            }

            $iTokenLength = (int) Yii::app()->request->getPost('tokenlength');
            //token length has to be at least 5, otherwise set it to default (15)
            if ($iTokenLength !== -1) {
                if ($iTokenLength < 5) {
                    $iTokenLength = 15;
                }
                if ($iTokenLength > 36) {
                    $iTokenLength = 36;
                }
            }

            $aInsertData = array(
                'expires' => $sExpiryDate,
                'startdate' => $sStartDate,
                'template' => App()->request->getPost('template'),
                'admin' => App()->request->getPost('admin'),
                'active' => 'N',
                'anonymized' => App()->request->getPost('anonymized'),
                'faxto' => App()->request->getPost('faxto'),
                'format' => App()->request->getPost('format'),
                'savetimings' => App()->request->getPost('savetimings'),
                'language' => App()->request->getPost('language', Yii::app()->session['adminlang']),
                'datestamp' => App()->request->getPost('datestamp'),
                'ipaddr' => App()->request->getPost('ipaddr'),
                'ipanonymize' => App()->request->getPost('ipanonymize'),
                'refurl' => App()->request->getPost('refurl'),
                'usecookie' => App()->request->getPost('usecookie'),
                'emailnotificationto' => App()->request->getPost('emailnotificationto'),
                'allowregister' => App()->request->getPost('allowregister'),
                'allowsave' => App()->request->getPost('allowsave'),
                'navigationdelay' => App()->request->getPost('navigationdelay'),
                'autoredirect' => App()->request->getPost('autoredirect'),
                'showxquestions' => App()->request->getPost('showxquestions'),
                'showgroupinfo' => App()->request->getPost('showgroupinfo'),
                'showqnumcode' => App()->request->getPost('showqnumcode'),
                'shownoanswer' => App()->request->getPost('shownoanswer'),
                'showwelcome' => App()->request->getPost('showwelcome'),
                'allowprev' => App()->request->getPost('allowprev'),
                'questionindex' => App()->request->getPost('questionindex'),
                'nokeyboard' => App()->request->getPost('nokeyboard'),
                'showprogress' => App()->request->getPost('showprogress'),
                'printanswers' => App()->request->getPost('printanswers'),
                'listpublic' => App()->request->getPost('listpublic'),
                'htmlemail' => App()->request->getPost('htmlemail'),
                'sendconfirmation' => App()->request->getPost('sendconfirmation'),
                'tokenanswerspersistence' => App()->request->getPost('tokenanswerspersistence'),
                'alloweditaftercompletion' => App()->request->getPost('alloweditaftercompletion'),
                'usecaptcha' => Survey::saveTranscribeCaptchaOptions(),
                'publicstatistics' => App()->request->getPost('publicstatistics'),
                'publicgraphs' => App()->request->getPost('publicgraphs'),
                'assessments' => App()->request->getPost('assessments'),
                'emailresponseto' => App()->request->getPost('emailresponseto'),
                'tokenlength' => $iTokenLength,
                'gsid'  => App()->request->getPost('gsid', '1'),
                'adminemail' => Yii::app()->request->getPost('adminemail'),
                'bounce_email' => Yii::app()->request->getPost('bounce_email'),

            );

            $warning = '';

            if (!is_null($iSurveyID)) {
                $aInsertData['sid'] = $iSurveyID;
            }

            $newSurvey = Survey::model()->insertNewSurvey($aInsertData);
            if (!$newSurvey->sid) {
                safeDie('Survey could not be created.'); // No error management ?
            }
            $iNewSurveyid = $newSurvey->sid;
            $this->aData['surveyid'] = $newSurvey->sid;; //import to render correct layout in before_render
            // Prepare locale data for surveys_language_settings table
            $sTitle          = Yii::app()->request->getPost('surveyls_title');
            $sDescription    = Yii::app()->request->getPost('description');
            $sWelcome        = Yii::app()->request->getPost('welcome');

            $sTitle          = html_entity_decode($sTitle, ENT_QUOTES, "UTF-8");
            $sDescription    = html_entity_decode($sDescription, ENT_QUOTES, "UTF-8");
            $sWelcome        = html_entity_decode($sWelcome, ENT_QUOTES, "UTF-8");

            // Fix bug with FCKEditor saving strange BR types
            $sTitle       = fixCKeditorText($sTitle);
            $sDescription = fixCKeditorText($sDescription);
            $sWelcome     = fixCKeditorText($sWelcome);


            // Insert base language into surveys_language_settings table
            $aInsertData = array(
                'surveyls_survey_id'           => $iNewSurveyid,
                'surveyls_title'               => $sTitle,
                'surveyls_description'         => $sDescription,
                'surveyls_welcometext'         => $sWelcome,
                'surveyls_language'            => Yii::app()->request->getPost('language'),
                'surveyls_urldescription'      => Yii::app()->request->getPost('urldescrip', ''),
                'surveyls_endtext'             => Yii::app()->request->getPost('endtext', ''),
                'surveyls_url'                 => Yii::app()->request->getPost('url', ''),
                'surveyls_dateformat'          => (int)Yii::app()->request->getPost('dateformat'),
                'surveyls_numberformat'        => (int)Yii::app()->request->getPost('numberformat'),
                'surveyls_policy_notice'       => App()->request->getPost('surveyls_policy_notice'),
                'surveyls_policy_notice_label' => App()->request->getPost('surveyls_policy_notice_label')
            );

            $langsettings = new SurveyLanguageSetting;
           $isLanguageSaved = $langsettings->insertNewSurvey($aInsertData);

            // Update survey permissions
            Permission::model()->giveAllSurveyPermissions(Yii::app()->session['loginID'], $iNewSurveyid);

            // This will force the generation of the entry for survey group
            TemplateConfiguration::checkAndcreateSurveyConfig($iNewSurveyid);
            $createSample = App()->request->getPost('createsample');
            $createSampleChecked = ($createSample === 'on');

            // Figure out destination
            if ($createSampleChecked) {
                $iNewGroupID = $this->createSampleGroup($iNewSurveyid);
                $iNewQuestionID = $this->createSampleQuestion($iNewSurveyid, $iNewGroupID);

                Yii::app()->setFlashMessage($warning.gT("Your new survey was created. 
                We also created a first question group and an example question for you."), 'info');
                $landOnSideMenuTab   = 'structure';
                $redirecturl = $this->getSurveyAndSidemenueDirectionURL(
                    $iNewSurveyid,
                    $iNewGroupID,
                    $iNewQuestionID,
                    $landOnSideMenuTab
                );
            } else {
                $redirecturl = $this->createUrl(
                    'surveyAdministration/view/',
                    ['iSurveyID'=>$iNewSurveyid]
                   // ['surveyid'=>$iNewSurveyid]
                );
                Yii::app()->setFlashMessage($warning.gT("Your new survey was created."), 'info');
            }
            return Yii::app()->getController()->renderPartial(
                '/admin/super/_renderJson',
                array(
                    'data' => array(
                        'redirecturl' => $redirecturl,
                    )
                ),
                false,
                false
            );
        }
        $this->redirect(Yii::app()->request->urlReferrer);
    }

    /**
     * todo: what is this? why do we need it?
     *(it'S just an js-script alert window rendert here ...)
     *
     * @return void
     * @todo   Document me
     */
    public function actionFakebrowser()
    {
        $this->renderPartial('/admin/survey/newSurveyBrowserMessage', array());
    }

    /**
     * Function responsible to import survey resources from a '.zip' file.
     *
     * @access public
     * @return void
     */
    public function actionImportsurveyresources()
    {
        $iSurveyID = Yii::app()->request->getPost('surveyid');

        //todo: permission check!!!

        if (!empty($iSurveyID)) {
            if (Yii::app()->getConfig('demoMode')) {
                Yii::app()->user->setFlash('error', gT("Demo mode only: Uploading files is disabled in this system."));
                $this->redirect(array('admin/survey/sa/editlocalsettings/surveyid/'.$iSurveyID));
            }

            // Create temporary directory
            // If dangerous content is unzipped
            // then no one will know the path
            Yii::import('application.helpers.common_helper', true);
            $extractdir  = createRandomTempDir();
            $zipfilename = $_FILES['the_file']['tmp_name'];
            $basedestdir = Yii::app()->getConfig('uploaddir')."/surveys";
            $destdir     = $basedestdir."/$iSurveyID/";

            Yii::app()->loadLibrary('admin.pclzip');
            $zip = new PclZip($zipfilename);

            if (!is_writeable($basedestdir)) {
                Yii::app()->user->setFlash('error', sprintf(gT("Incorrect permissions in your %s folder."),
                    $basedestdir));
                $this->redirect(array('admin/survey/sa/editlocalsettings/surveyid/'.$iSurveyID));
            }


            if (!is_dir($destdir)) {
                mkdir($destdir);
            }

            $aImportedFilesInfo = array();
            $aErrorFilesInfo = array();

            if (is_file($zipfilename)) {
                if ($zip->extract($extractdir) <= 0) {
                    Yii::app()->user->setFlash('error', gT("This file is not a valid ZIP file archive. Import failed. ").$zip->errorInfo(true));
                    $this->redirect(array('admin/survey/sa/editlocalsettings/surveyid/'.$iSurveyID));
                }
                // now read tempdir and copy authorized files only
                $folders = array('flash', 'files', 'images');

                $filteredImportedResources = new FilterImportedResources();

                foreach ($folders as $folder) {
                    list($_aImportedFilesInfo, $_aErrorFilesInfo) = $filteredImportedResources->filterImportedResources(
                        $extractdir."/".$folder,
                        $destdir.$folder
                    );
                    $aImportedFilesInfo = array_merge($aImportedFilesInfo, $_aImportedFilesInfo);
                    $aErrorFilesInfo = array_merge($aErrorFilesInfo, $_aErrorFilesInfo);
                }

                // Deletes the temp directory
                rmdirr($extractdir);

                // Delete the temporary file
                unlink($zipfilename);

                if (is_null($aErrorFilesInfo) && is_null($aImportedFilesInfo)) {
                    Yii::app()->user->setFlash('error', gT("This ZIP archive contains no valid Resources files. Import failed."));
                    $this->redirect(array('admin/survey/sa/editlocalsettings/surveyid/'.$iSurveyID));
                    //todo editlocalsettings does not exist anymore ...
                }
            } else {
                Yii::app()->setFlashMessage(gT("An error occurred uploading your file. This may be caused by incorrect permissions for the application /tmp folder."), 'error');
                $this->redirect(array('admin/survey/sa/editlocalsettings/surveyid/'.$iSurveyID));
            }
            $aData = array(
                'aErrorFilesInfo' => $aErrorFilesInfo,
                'aImportedFilesInfo' => $aImportedFilesInfo,
                'surveyid' => $iSurveyID
            );
            $aData['display']['menu_bars']['surveysummary'] = true;

            $this->aData = $aData;
            $this->render('importSurveyResources_view', [
                'aErrorFilesInfo' => $this->aData['aErrorFilesInfo'],
                'aImportedFilesInfo' => $this->aData['aImportedFilesInfo'],
            ]);
        }
    }

    /**
     * Function to call current Editor Values by Ajax
     *
     * @param integer $sid Given Survey ID
     *
     * @return JSON
     */
    public function actionGetCurrentEditorValues($sid)
    {
        $iSurveyId = (int) $sid;
        $oSurvey = Survey::model()->findByPk($iSurveyId);

        $updatePermission = $oSurvey == null
            ? Permission::model()->hasGlobalPermission('surveys', 'create')
            : Permission::model()->hasSurveyPermission($iSurveyId, 'surveylocale', 'update');

        $aLanguages = [];
        $aReturner = [
            "surveyTitle" => [],
            "welcome" => [],
            "description" => [],
            "endText" => [],
            "endUrl" => [],
            "endUrlDescription" => [],
            "dateFormat" => [],
            "decimalDivider" => [],
            "permissions" => [
                "update" => $updatePermission,
                "editorpreset" => Yii::app()->session['htmleditormode'],
            ]
        ];

        if ($oSurvey == null) {
            $defaultLanguage = App()->getConfig('defaultlang');
            $aLanguageDetails = getLanguageDetails($defaultLanguage);
            $aLanguages = [$defaultLanguage => getLanguageCodefromLanguage($defaultLanguage)];
            $aReturner["surveyTitle"][$defaultLanguage] = "";
            $aReturner["welcome"][$defaultLanguage] = "";
            $aReturner["description"][$defaultLanguage] = "";
            $aReturner["endText"][$defaultLanguage] = "";
            $aReturner["endUrl"][$defaultLanguage] = "";
            $aReturner["endUrlDescription"][$defaultLanguage] = "";
            $aReturner["dateFormat"][$defaultLanguage] = $aLanguageDetails['dateformat'];
            $aReturner["decimalDivider"][$defaultLanguage] = $aLanguageDetails['radixpoint'];

            return Yii::app()->getController()->renderPartial(
                '/admin/super/_renderJson',
                ['data' => [
                    "textdata" => $aReturner,
                    "languages" => $aLanguages
                ]],
                false,
                false
            );
        }

        foreach ($oSurvey->allLanguages as $sLanguage) {
            $aLanguages[$sLanguage] = getLanguageNameFromCode($sLanguage, false);
            $aReturner["surveyTitle"][$sLanguage] = $oSurvey->languagesettings[$sLanguage]->surveyls_title;
            $aReturner["welcome"][$sLanguage] = $oSurvey->languagesettings[$sLanguage]->surveyls_welcometext;
            $aReturner["description"][$sLanguage] = $oSurvey->languagesettings[$sLanguage]->surveyls_description;
            $aReturner["endText"][$sLanguage] = $oSurvey->languagesettings[$sLanguage]->surveyls_endtext;
            $aReturner["endUrl"][$sLanguage] = $oSurvey->languagesettings[$sLanguage]->surveyls_url;
            $aReturner["endUrlDescription"][$sLanguage] = $oSurvey->languagesettings[$sLanguage]->surveyls_urldescription;
            $aReturner["dateFormat"][$sLanguage] = $oSurvey->languagesettings[$sLanguage]->surveyls_dateformat;
            $aReturner["decimalDivider"][$sLanguage] = $oSurvey->languagesettings[$sLanguage]->surveyls_numberformat;
        }

        return Yii::app()->getController()->renderPartial(
            '/admin/super/_renderJson',
            ['data' => [
                "textdata" => $aReturner,
                "languages" => $aLanguages
            ]],
            false,
            false
        );
    }

    /**
     * Massive action in ListSurveysWidget ...
     * Ajax request
     *
     * @return void
     * @throws CException
     */
    public function actionChangeMultipleTheme()
    {
        $sSurveys = $_POST['sItems'];
        $aSIDs = json_decode($sSurveys);
        $aResults = array();

        $sTemplate = App()->request->getPost('theme');

        foreach ($aSIDs as $iSurveyID) {
            $aResults = $this->changeTemplate($iSurveyID, $sTemplate, $aResults, true);
        }

        $this->renderPartial(
            'ext.admin.survey.ListSurveysWidget.views.massive_actions._action_results',
            array('aResults'=>$aResults,'successLabel'=>$sTemplate)
        );
    }

    /**
     * Change survey group for multiple survey at once.
     * Called from survey list massive actions
     *
     * REFACTORED in SurveyAdministrationController
     *
     * @return void
     * @throws CException
     */
    public function actionChangeMultipleSurveyGroup()
    {
        $sSurveys = $_POST['sItems'];
        $aSIDs = json_decode($sSurveys);
        $aResults = array();

        $iSurveyGroupId = sanitize_int(App()->request->getPost('surveygroupid'));

        foreach ($aSIDs as $iSurveyID) {
            $oSurvey = Survey::model()->findByPk((int)$iSurveyID);
            $oSurvey->gsid = $iSurveyGroupId;
            $aResults[$iSurveyID]['title']  = $oSurvey->correct_relation_defaultlanguage->surveyls_title;
            $aResults[$iSurveyID]['result']= $oSurvey->save();
        }

        Yii::app()->getController()->renderPartial(
            'ext.admin.survey.ListSurveysWidget.views.massive_actions._action_results',
            array('aResults'=>$aResults,'successLabel'=>gT("Success"))
        );
    }

    /**
     * Toggles Quick action
     *
     *  @return string | null
     * @throws CException
     */
    public function actionToggleQuickAction()
    {
        $quickactionstate = (int) SettingsUser::getUserSettingValue('quickaction_state');

        switch ($quickactionstate) {
            case null:
            case 0:
                $save = SettingsUser::setUserSetting('quickaction_state', 1);
                break;
            case 1:
                $save = SettingsUser::setUserSetting('quickaction_state', 0);
                break;
            default:
                $save = null;
        }
        return Yii::app()->getController()->renderPartial(
            '/admin/super/_renderJson',
            array(
                'data' => [
                    'success' => $save,
                    'newState'=> SettingsUser::getUserSettingValue('quickaction_state')
                ],
            ),
            false,
            false
        );
    }

    /**
     * AjaxRequest get questionGroup with containing questions
     *
     * @param int $surveyid Given Survey ID
     *
     * @return string|string[]
     * @throws CException
     */
    public function actionGetAjaxQuestionGroupArray($surveyid)
    {
        $iSurveyID = sanitize_int($surveyid);

        if (!Permission::model()->hasSurveyPermission($iSurveyID, 'surveycontent', 'read')) {
            Yii::app()->user->setFlash('error', gT("Access denied"));
            $this->redirect(Yii::app()->createUrl('/admin'));
        }

        $survey    = Survey::model()->findByPk($iSurveyID);
        $baselang  = $survey->language;
        $setting_entry = 'last_question_'.Yii::app()->user->getId().'_'.$iSurveyID;
        $lastquestion = getGlobalSetting($setting_entry);
        $setting_entry = 'last_question_'.Yii::app()->user->getId().'_'.$iSurveyID.'_gid';
        $lastquestiongroup = getGlobalSetting($setting_entry);

        $aGroups = QuestionGroup::model()->findAllByAttributes(
            array('sid' => $iSurveyID),
            array('order'=>'group_order ASC')
        );
        $aGroupViewable = array();
        if (count($aGroups)) {
            foreach ($aGroups as $group) {
                $curGroup = $group->attributes;
                $curGroup['group_name'] = $group->questiongroupl10ns[$baselang]->group_name;
                $curGroup['link'] = $this->createUrl(
                    "questionGroupsAdministration/view",
                    ['surveyid' => $surveyid, 'gid' => $group->gid]
                );
                $group->aQuestions = Question::model()->findAllByAttributes(
                    array("sid"=>$iSurveyID,
                          "gid"=>$group['gid'],
                          'parent_qid'=>0),
                    array('order'=>'question_order ASC')
                );
                $curGroup['questions'] = array();
                foreach ($group->aQuestions as $question) {
                    if (is_object($question)) {
                        $curQuestion = $question->attributes;
                        $curQuestion['link'] = $this->createUrl(
                            "questionAdministration/view",
                            ['surveyid' => $surveyid, 'gid' => $group->gid, 'qid'=>$question->qid]
                        );
                        $curQuestion['editLink'] = $this->createUrl(
                            "questionAdministration/view",
                            ['surveyid' => $surveyid, 'gid' => $group->gid, 'qid'=>$question->qid]
                        );
                        $curQuestion['hidden'] = isset($question->questionattributes['hidden']) &&
                            !empty($question->questionattributes['hidden']->value);
                        $questionText = isset($question->questionl10ns[$baselang])
                            ? $question->questionl10ns[$baselang]->question
                            : '';
                        $curQuestion['question_flat'] = viewHelper::flatEllipsizeText($questionText, true);
                        $curGroup['questions'][] = $curQuestion;
                    }

                }
                $aGroupViewable[] = $curGroup;
            }
        }

        return $this->renderPartial(
            '/admin/super/_renderJson',
            array(
                'data' => array(
                    'groups' => $aGroupViewable,
                    'settings' => array(
                        'lastquestion' => $lastquestion,
                        'lastquestiongroup' => $lastquestiongroup,
                    ),
                )
            ),
            false,
            false
        );
    }

    /**
     * Ajaxified get MenuItems with containing questions
     *
     * @param int $surveyid Given Survey ID
     * @param string $position Given Position
     *
     * @return string|string[]
     * @throws CException
     */
    public function actionGetAjaxMenuArray($surveyid, $position = '')
    {
        $iSurveyID = sanitize_int($surveyid);

        //todo permission check ?!?

        $survey    = Survey::model()->findByPk($iSurveyID);
        $menus = $survey->getSurveyMenus($position);
        return $this->renderPartial(
            '/admin/super/_renderJson',
            array(
                'data' => [
                    'menues'=> $menus,
                    'settings' => array(
                        'extrasettings' => false,
                        'parseHTML' => false,
                    )
                ]
            ),
            false,
            false
        );
    }

    /**
     * Method to call current date information by ajax
     *
     * @param int $sid Given Survey ID
     *
     * @return JSON | string
     * @throws CException
     */
    public function actionGetDateFormatOptions($sid)
    {
        $aRawDateFormats = getDateFormatData();

        return $this->renderPartial(
            '/admin/super/_renderJson',
            [
                'data' => array_map(
                    function ($aDateFormats) {
                        return $aDateFormats['dateformat'];
                    },
                    $aRawDateFormats
                )
            ],
            false,
            false
        );
    }

    /**
     * Method to store data edited in the the text editor component
     *
     * @param integer $sid Survey ID
     *
     * @return JSON | string
     * @throws CException
     */
    public function actionSaveTextData($sid)
    {
        $iSurveyId = (int) $sid;
        $changes = Yii::app()->request->getPost('changes');
        $aSuccess = [];
        foreach ($changes as $sLanguage => $contentChange) {
            //todo: better not to use sql-statement like this in a foreach (performance!!!)
            $oSurveyLanguageSetting = SurveyLanguageSetting::model()->findByPk(
                ["surveyls_survey_id" => $iSurveyId, "surveyls_language" => $sLanguage]
            );
            if ($oSurveyLanguageSetting == null) {
                $oSurveyLanguageSetting = new SurveyLanguageSetting();
                $oSurveyLanguageSetting->surveyls_survey_id = $iSurveyId;
                $oSurveyLanguageSetting->surveyls_language = $sLanguage;
            }
            $oSurveyLanguageSetting->surveyls_title = $contentChange['surveyTitle'];
            $oSurveyLanguageSetting->surveyls_welcometext = $contentChange['welcome'];
            $oSurveyLanguageSetting->surveyls_description = $contentChange['description'];
            $oSurveyLanguageSetting->surveyls_endtext = $contentChange['endText'];
            $oSurveyLanguageSetting->surveyls_url = $contentChange['endUrl'];
            $oSurveyLanguageSetting->surveyls_urldescription = $contentChange['endUrlDescription'];
            $oSurveyLanguageSetting->surveyls_dateformat = $contentChange['dateFormat'];
            $oSurveyLanguageSetting->surveyls_numberformat = $contentChange['decimalDivider'];
            $aSuccess[$sLanguage] = $oSurveyLanguageSetting->save();
            unset($oSurveyLanguageSetting);
        }

        $success = array_reduce(
            $aSuccess,
            function ($carry, $subsuccess) {
                return $carry = $carry && $subsuccess;
            },
            true
        );

        return $this->renderPartial(
            '/admin/super/_renderJson',
            ['data' => [
                "success" => $success,
                "message" => ($success ? gT("Survey texts were saved successfully.") : gT("Error saving survey texts"))
            ]
            ],
            false,
            false
        );
    }

    /**
     * Collect the data necessary for the data security settings and return a JSON document
     *
     * @param integer $sid Survey ID
     *
     * @return JSON | string
     *
     * @throws CException
     */
    public function actionGetDataSecTextSettings($sid = null)
    {
        $iSurveyId = (int) $sid;
        $oSurvey = Survey::model()->findByPk($iSurveyId);

        $aLanguages = [];
        $aReturner = [
            "dataseclabel" => [],
            "datasecmessage" => [],
            "datasecerror" => [],
        ];

        if ($oSurvey == null ) {
            $defaultLanguage = App()->getConfig('defaultlang');
            $aLanguages = [$defaultLanguage => getLanguageCodefromLanguage($defaultLanguage)];
            $aReturner["datasecmessage"][$defaultLanguage] = "";
            $aReturner["datasecerror"][$defaultLanguage] = "";
            $aReturner["dataseclabel"][$defaultLanguage] = "";

            return $this->renderPartial(
                '/admin/super/_renderJson',
                ['data' => [
                    "showsurveypolicynotice" => 0,
                    "textdata" => $aReturner,
                    "languages" => $aLanguages,
                    "permissions" => [
                        "update" => Permission::model()->hasGlobalPermission('surveys', 'create'),
                        "editorpreset" => Yii::app()->session['htmleditormode'],
                    ]
                ]],
                false,
                false
            );
        }

        foreach ($oSurvey->allLanguages as $sLanguage) {
            $aLanguages[$sLanguage] = getLanguageNameFromCode($sLanguage, false);
            $aReturner["datasecmessage"][$sLanguage] = $oSurvey->languagesettings[$sLanguage]->surveyls_policy_notice;
            $aReturner["datasecerror"][$sLanguage] = $oSurvey->languagesettings[$sLanguage]->surveyls_policy_error;
            $aReturner["dataseclabel"][$sLanguage] = $oSurvey->languagesettings[$sLanguage]->surveyls_policy_notice_label;
        }

        return $this->renderPartial(
            '/admin/super/_renderJson',
            ['data' => [
                "showsurveypolicynotice" => $oSurvey->showsurveypolicynotice,
                "textdata" => $aReturner,
                "languages" => $aLanguages,
                "permissions" => [
                    "update" => Permission::model()->hasSurveyPermission($iSurveyId, 'surveysecurity', 'update'),
                    "editorpreset" => Yii::app()->session['htmleditormode'],
                ]
            ]],
            false,
            false
        );
    }

    /**
     * Method to store data edited in the the data security text editor component
     *
     * @param integer $sid Survey ID
     *
     * @return JSON | string
     *
     * @throws CException
     */
    public function saveDataSecTextData($sid)
    {
        $iSurveyId = (int) $sid;
        $oSurvey = Survey::model()->findByPk($iSurveyId);
        $changes = Yii::app()->request->getPost('changes', []);
        $aSuccess = [];

        $oSurvey->showsurveypolicynotice = isset($changes['showsurveypolicynotice']) ? $changes['showsurveypolicynotice'] : 0;
        $aSuccess[] = $oSurvey->save();
        foreach ($oSurvey->allLanguages as $sLanguage ) {
            $oSurveyLanguageSetting = SurveyLanguageSetting::model()->findByPk(["surveyls_survey_id" => $iSurveyId, "surveyls_language" => $sLanguage]);
            if ($oSurveyLanguageSetting == null) {
                $oSurveyLanguageSetting = new SurveyLanguageSetting();
                $oSurveyLanguageSetting->surveyls_title = "";
                $oSurveyLanguageSetting->surveyls_survey_id = $iSurveyId;
                $oSurveyLanguageSetting->surveyls_language = $sLanguage;
            }

            $oSurveyLanguageSetting->surveyls_policy_notice = isset($changes['datasecmessage'][$sLanguage]) ? $changes['datasecmessage'][$sLanguage] : '';
            $oSurveyLanguageSetting->surveyls_policy_error = isset($changes['datasecerror'][$sLanguage]) ? $changes['datasecerror'][$sLanguage] : '';
            $oSurveyLanguageSetting->surveyls_policy_notice_label = isset($changes['dataseclabel'][$sLanguage]) ? $changes['dataseclabel'][$sLanguage] : '';
            $aSuccess[$sLanguage] = $oSurveyLanguageSetting->save();
            unset($oSurveyLanguageSetting);
        }

        $success = array_reduce(
            $aSuccess,
            function ($carry, $subsuccess) {
                return $carry = $carry && $subsuccess;
            },
            true
        );

        return $this->renderPartial(
            '/admin/super/_renderJson',
            ['data' => [
                "success" => $success,
                "message" => ($success ? gT("Successfully saved privacy policy text") : gT("Error saving privacy policy text"))
            ]
            ],
            false,
            false
        );
    }

    /**
     * Apply current theme options for imported survey theme
     *
     * @param integer $iSurveyID The survey ID of imported survey
     *
     * @return void
     */
    public function actionApplythemeoptions($iSurveyID = 0)
    {
        if ((int)$iSurveyID > 0 && Yii::app()->request->isPostRequest) {
            $oSurvey = Survey::model()->findByPk($iSurveyID);
            $sTemplateName = $oSurvey->template;
            $aThemeOptions = json_decode(App()->request->getPost('themeoptions', ''));

            if (!empty($aThemeOptions)) {
                $oSurveyConfig = TemplateConfiguration::getInstance($sTemplateName, null, $iSurveyID);
                if ($oSurveyConfig->options === 'inherit') {
                    $oSurveyConfig->setOptionKeysToInherit();
                }

                foreach ($aThemeOptions as $key => $value) {
                    $oSurveyConfig->setOption($key, $value);
                }
                $oSurveyConfig->save();
            }
        }
        $this->redirect(array('surveyAdministration/view/surveyid/'.$iSurveyID));
    }

    /**
     * Upload an image in directory
     *
     * @return json |string
     *
     * @throws CException
     */
    public function actionUploadimagefile()
    {
        $iSurveyID = Yii::app()->request->getPost('surveyid');
        $success = false;
        $debug = [];
        if (!Permission::model()->hasSurveyPermission($iSurveyID, 'surveycontent', 'update')) {
            return $this->renderPartial(
                '/admin/super/_renderJson',
                array('data' => ['success' => $success, 'message' => gT("You don't have sufficient permissions to upload images in this survey"), 'debug' => $debug]),
                false,
                false
            );
        }
        $debug[] = $_FILES;
        if (empty($_FILES)) {
            $uploadresult = gT("No file was uploaded.");
            return $this->renderPartial(
                '/admin/super/_renderJson',
                array('data' => ['success' => $success, 'message' => $uploadresult, 'debug' => $debug]),
                false,
                false
            );
        }
        if ($_FILES['file']['error'] == 1 || $_FILES['file']['error'] == 2) {
            $uploadresult = sprintf(gT("Sorry, this file is too large. Only files up to %01.2f MB are allowed."), getMaximumFileUploadSize() / 1024 / 1024);
            return $this->renderPartial(
                '/admin/super/_renderJson',
                array('data' => ['success' => $success, 'message' => $uploadresult, 'debug' => $debug]),
                false,
                false
            );
        }
        $checkImage = LSYii_ImageValidator::validateImage($_FILES["file"]);
        if ($checkImage['check'] === false) {
            return $this->renderPartial(
                '/admin/super/_renderJson',
                array('data' => ['success' => $success, 'message' => $checkImage['uploadresult'], 'debug' => $checkImage['debug']]),
                false,
                false
            );
        }
        $surveyDir = Yii::app()->getConfig('uploaddir')."/surveys/".$iSurveyID;
        if (!is_dir($surveyDir)) {
            @mkdir($surveyDir);
        }
        if (!is_dir($surveyDir."/images")) {
            @mkdir($surveyDir."/images");
        }
        $destdir = $surveyDir."/images/";
        if (!is_writeable($destdir)) {
            $uploadresult = sprintf(gT("Incorrect permissions in your %s folder."), $destdir);
            return $this->renderPartial(
                '/admin/super/_renderJson',
                array('data' => ['success' => $success, 'message' => $uploadresult, 'debug' => $debug]),
                false,
                false
            );
        }

        $filename = sanitize_filename($_FILES['file']['name'], false, false, false); // Don't force lowercase or alphanumeric
        $fullfilepath = $destdir.$filename;
        $debug[] = $destdir;
        $debug[] = $filename;
        $debug[] = $fullfilepath;
        if (!@move_uploaded_file($_FILES['file']['tmp_name'], $fullfilepath)) {
            $uploadresult = gT("An error occurred uploading your file. This may be caused by incorrect permissions for the application /tmp folder.");
        } else {
            $uploadresult = sprintf(gT("File %s uploaded"), $filename);
            $success = true;
        };
        return $this->renderPartial(
            '/admin/super/_renderJson',
            array('data' => ['success' => $success, 'message' => $uploadresult, 'debug' => $debug]),
            false,
            false
        );
    }

    /**
     * Returns JSON Data for Token Top Bar.
     *
     * @todo: this should go into tokens controller ...
     *
     * @param int  $sid       Given Survey ID
     * @param bool $onlyclose Close
     *
     * @return string
     */
    public function actionGetTokenTopBar($sid, $onlyclose = false)
    {
        $oSurvey   = Survey::model()->findByPk($sid);

        return $this->renderPartial(
            'token_bar',
            array(
                'oSurvey'     => $oSurvey,
                'sid'     => $sid,
                'onlyclose' => !$oSurvey->hasTokensTable
            ),
            false,
            false
        );
    }

    /**
     * This Method is returning the Data for Survey Top Bar Component
     * for Vue JS as JSON.
     *
     * todo: is this still in use? cannot find any usage ...
     *
     * @deprecated not used anymore?
     *
     * @param int  $sid        Given Survey ID
     * @param bool $saveButton Renders Save Button
     *
     * @return string|string[]|null
     * @throws CException
     */
    public function actionGetSurveyTopbar($sid, $saveButton = false)
    {
        $oSurvey                       = Survey::model()->findByPk($sid);
        $hasSurveyContentPermission    = Permission::model()->hasSurveyPermission($sid, 'surveycontent', 'update');
        $hasSurveyActivationPermission = Permission::model()->hasSurveyPermission($sid, 'surveyactivation', 'update');
        $hasDeletePermission           = Permission::model()->hasSurveyPermission($sid, 'survey', 'delete');
        $hasSurveyTranslatePermission  = Permission::model()->hasSurveyPermission($sid, 'translations', 'read');
        $hasSurveyReadPermission       = Permission::model()->hasSurveyPermission($sid, 'surveycontent', 'read');
        $hasSurveyTokensPermission     = Permission::model()->hasSurveyPermission($sid, 'surveysettings', 'update')
            || Permission::model()->hasSurveyPermission($sid, 'tokens', 'create');
        $hasResponsesCreatePermission  = Permission::model()->hasSurveyPermission($sid, 'responses', 'create');
        $hasResponsesReadPermission    = Permission::model()->hasSurveyPermission($sid, 'responses', 'read');
        $hasResponsesStatisticsReadPermission = Permission::model()->hasSurveyPermission($sid, 'statistics', 'read');

        $isActive  = $oSurvey->active == 'Y';
        $condition = array('sid' => $sid, 'parent_qid' => 0);
        $sumcount  = Question::model()->countByAttributes($condition);
        $countLanguage = count($oSurvey->allLanguages);
        $hasAdditionalLanguages = (count($oSurvey->additionalLanguages) > 0);
        $canactivate = $sumcount > 0 && $hasSurveyActivationPermission;
        $expired     = $oSurvey->expires != '' && ($oSurvey->expires < dateShift(date("Y-m-d H:i:s"),
                    "Y-m-d H:i", Yii::app()->getConfig('timeadjust')));
        $notstarted  = ($oSurvey->startdate != '') && ($oSurvey->startdate > dateShift(date("Y-m-d H:i:s"),
                    "Y-m-d H:i", Yii::app()->getConfig('timeadjust')));

        if (!$isActive) {
            $context = gT("Preview survey");
            $contextbutton = 'preview_survey';
        } else {
            $context =  gT("Execute survey");
            $contextbutton = 'execute_survey';
        }

        $language = $oSurvey->language;
        $conditionsCount = Condition::model()->with(array('questions'=>array('condition'=>'sid ='.$sid)))->count();
        $oneLanguage     = (count($oSurvey->allLanguages) == 1);

        // Put menu items in tools menu
        $event = new PluginEvent('beforeToolsMenuRender', $this);
        $event->set('surveyId', $oSurvey->sid);
        App()->getPluginManager()->dispatchEvent($event);
        $extraToolsMenuItems = $event->get('menuItems');

        // Add new menus in survey bar
        $event = new PluginEvent('beforeSurveyBarRender', $this);
        $event->set('surveyId', $oSurvey->sid);
        App()->getPluginManager()->dispatchEvent($event);
        $beforeSurveyBarRender = $event->get('menus');

        return $this->renderPartial(
            'survey_topbar',
            array(
                'sid' => $sid,
                'canactivate' => $canactivate,
                'expired' => $expired,
                'notstarted' => $notstarted,
                'context' => $context,
                'contextbutton' => $contextbutton,
                'language' => $language,
                'sumcount' => $sumcount,
                'hasSurveyContentPermission' => $hasSurveyContentPermission,
                'countLanguage' => $countLanguage,
                'hasDeletePermission' => $hasDeletePermission,
                'hasSurveyTranslatePermission' => $hasSurveyTranslatePermission,
                'hasAdditionalLanguages' => $hasAdditionalLanguages,
                'conditionsCount' => $conditionsCount,
                'hasSurveyReadPermission' => $hasSurveyReadPermission,
                'oneLanguage' => $oneLanguage,
                'hasSurveyTokensPermission'    => $hasSurveyTokensPermission,
                'hasResponsesCreatePermission' => $hasResponsesCreatePermission,
                'hasResponsesReadPermission'   => $hasResponsesReadPermission,
                'hasSurveyActivationPermission'   => $hasSurveyActivationPermission,
                'hasResponsesStatisticsReadPermission' => $hasResponsesStatisticsReadPermission,
                'addSaveButton'  => $saveButton,
                'extraToolsMenuItems' => $extraToolsMenuItems ?? [],
                'beforeSurveyBarRender' => $beforeSurveyBarRender ?? []
            ),
            false,
            false
        );
    }



    /** ************************************************************************************************************ */
    /**                      The following functions could be moved to model or service classes                      */
    /** **********************************************************************************************************++ */

    /**
     * Update the theme of a survey
     *
     * @param int     $iSurveyID Survey ID
     * @param string  $template  The survey theme name
     * @param array   $aResults  If the method is called from changeMultipleTheme(), it will update its array of results
     * @param boolean $bReturn   Should the method update and return aResults
     *
     * @return mixed                 null or array
     *
     * @access public
     */
    public function changeTemplate($iSurveyID, $template, $aResults = null, $bReturn = false)
    {
        $iSurveyID  = sanitize_int($iSurveyID);
        $sTemplate  = sanitize_dirname($template);

        $survey           = Survey::model()->findByPk($iSurveyID);


        if (!Permission::model()->hasSurveyPermission($iSurveyID, 'surveyactivation', 'update')) {
            if (!empty($bReturn)) {
                $aResults[$iSurveyID]['title']  = $survey->correct_relation_defaultlanguage->surveyls_title;
                $aResults[$iSurveyID]['result'] = false;
                return $aResults;
            } else {
                die('No permission');
            }
        }

        $survey->template = $sTemplate;
        $survey->save();

        $oTemplateConfiguration = $survey->surveyTemplateConfiguration;
        $oTemplateConfiguration->template_name = $sTemplate;
        $oTemplateConfiguration->save();

        // This will force the generation of the entry for survey group
        TemplateConfiguration::checkAndcreateSurveyConfig($iSurveyID);

        if (!empty($bReturn)) {
            $aResults[$iSurveyID]['title']  = $survey->correct_relation_defaultlanguage->surveyls_title;
            $aResults[$iSurveyID]['result'] = true;
            return $aResults;
        }
    }

    /**
     * This method will return the url for the current survey and set
     * the direction for the sidemenue.
     *
     * @param integer $sid               Given Survey ID
     * @param integer $gid               Given Group ID
     * @param integer $qid               Given Question ID
     * @param string  $landOnSideMenuTab Given SideMenuTab
     *
     * @return string
     */
    public function getSurveyAndSidemenueDirectionURL($sid, $gid, $qid, $landOnSideMenuTab)
    {
        $url = 'questionAdministration/view/';
        $params = [
            'surveyid' => $sid,
            'gid'      => $gid,
            'qid'      => $qid,
            'landOnSideMenuTab'   => $landOnSideMenuTab];
        return $this->createUrl($url, $params);
    }

    /**
     * This private function creates a sample group
     *
     * @param int $iSurveyID The survey ID that the sample group will belong to
     *
     * @return int
     */
    private function createSampleGroup($iSurveyID)
    {
        // Now create a new dummy group
        $sLanguage = Survey::model()->findByPk($iSurveyID)->language;
        $oGroup = new QuestionGroup();
        $oGroup->sid = $iSurveyID;
        $oGroup->group_order = 1;
        $oGroup->grelevance = '1';
        $oGroup->save();
        $oGroupL10ns = new QuestionGroupL10n();
        $oGroupL10ns->gid = $oGroup->gid;
        $oGroupL10ns->group_name = gt('My first question group', 'html', $sLanguage);
        $oGroupL10ns->language = $sLanguage;
        $oGroupL10ns->save();
        LimeExpressionManager::SetEMLanguage($sLanguage);
        return $oGroup->gid;
    }

    /**
     * This private function creates a sample question
     *
     * @param int $iSurveyID The survey ID that the sample question will belong to
     * @param int $iGroupID  The group ID that the sample question will belong to
     *
     * @return int
     */
    private function createSampleQuestion($iSurveyID, $iGroupID)
    {
        // Now create a new dummy question
        $sLanguage = Survey::model()->findByPk($iSurveyID)->language;
        $oQuestion = new Question;
        $oQuestion->sid = $iSurveyID;
        $oQuestion->gid = $iGroupID;
        $oQuestion->type = Question::QT_T_LONG_FREE_TEXT;
        $oQuestion->title = 'Q00';
        $oQuestion->mandatory = 'N';
        $oQuestion->relevance = '1';
        $oQuestion->question_order = 1;
        $oQuestion->save();
        $oQuestionLS = new QuestionL10n();
        $oQuestionLS->question = gt('A first example question. Please answer this question:', 'html', $sLanguage);
        $oQuestionLS->help = gt('This is a question help text.', 'html', $sLanguage);
        $oQuestionLS->language = $sLanguage;
        $oQuestionLS->qid = $oQuestion->qid;
        $oQuestionLS->save();
        return $oQuestion->qid;
    }

    /**
     * Adds some other important adata variables for frontend
     *
     * this function came from Layouthelper
     *
     * @param array $aData pointer to array (this array will be changed here!!)
     *
     * @throws CException
     */
    private function surveysummary(&$aData)
    {
        $iSurveyID = $aData['surveyid'];

        $aSurveyInfo = getSurveyInfo($iSurveyID);
        /** @var Survey $oSurvey */
        if (!isset($aData['oSurvey'])) {
            $aData['oSurvey'] = Survey::model()->findByPk($aData['surveyid']);
        }
        $oSurvey = $aData['oSurvey'];
        $activated = $aSurveyInfo['active'];

        $condition = array('sid' => $iSurveyID, 'parent_qid' => 0);
        $sumcount3 = Question::model()->countByAttributes($condition); //Checked
        $sumcount2 = QuestionGroup::model()->countByAttributes(array('sid' => $iSurveyID));

        //SURVEY SUMMARY
        $aAdditionalLanguages = $oSurvey->additionalLanguages;
        $surveysummary2 = [];
        if ($aSurveyInfo['anonymized'] != "N") {
            $surveysummary2[] = gT("Responses to this survey are anonymized.");
        } else {
            $surveysummary2[] = gT("Responses to this survey are NOT anonymized.");
        }
        if ($aSurveyInfo['format'] == "S") {
            $surveysummary2[] = gT("It is presented question by question.");
        } elseif ($aSurveyInfo['format'] == "G") {
            $surveysummary2[] = gT("It is presented group by group.");
        } else {
            $surveysummary2[] = gT("It is presented on one single page.");
        }
        if ($aSurveyInfo['questionindex'] > 0) {
            if ($aSurveyInfo['format'] == 'A') {
                $surveysummary2[] = gT("No question index will be shown with this format.");
            } elseif ($aSurveyInfo['questionindex'] == 1) {
                $surveysummary2[] = gT("A question index will be shown; participants will be able to jump between viewed questions.");
            } elseif ($aSurveyInfo['questionindex'] == 2) {
                $surveysummary2[] = gT("A full question index will be shown; participants will be able to jump between relevant questions.");
            }
        }
        if ($oSurvey->isDateStamp) {
            $surveysummary2[] = gT("Responses will be date stamped.");
        }
        if ($oSurvey->isIpAddr) {
            $surveysummary2[] = gT("IP Addresses will be logged");
        }
        if ($oSurvey->isRefUrl) {
            $surveysummary2[] = gT("Referrer URL will be saved.");
        }
        if ($oSurvey->isUseCookie) {
            $surveysummary2[] = gT("It uses cookies for access control.");
        }
        if ($oSurvey->isAllowRegister) {
            $surveysummary2[] = gT("If participant access codes are used, the public may register for this survey");
        }
        if ($oSurvey->isAllowSave && !$oSurvey->isTokenAnswersPersistence) {
            $surveysummary2[] = gT("Participants can save partially finished surveys");
        }
        if ($oSurvey->emailnotificationto != '') {
            $surveysummary2[] = gT("Basic email notification is sent to:").' '.htmlspecialchars($aSurveyInfo['emailnotificationto']);
        }
        if ($oSurvey->emailresponseto != '') {
            $surveysummary2[] = gT("Detailed email notification with response data is sent to:").' '.htmlspecialchars($aSurveyInfo['emailresponseto']);
        }

        $dateformatdetails = getDateFormatData(Yii::app()->session['dateformat']);
        if (trim($oSurvey->startdate) != '') {
            Yii::import('application.libraries.Date_Time_Converter');
            $datetimeobj = new Date_Time_Converter($oSurvey->startdate, 'Y-m-d H:i:s');
            $aData['startdate'] = $datetimeobj->convert($dateformatdetails['phpdate'].' H:i');
        } else {
            $aData['startdate'] = "-";
        }

        if (trim($oSurvey->expires) != '') {
            //$constructoritems = array($surveyinfo['expires'] , "Y-m-d H:i:s");
            Yii::import('application.libraries.Date_Time_Converter');
            $datetimeobj = new Date_Time_Converter($oSurvey->expires, 'Y-m-d H:i:s');
            //$datetimeobj = new Date_Time_Converter($surveyinfo['expires'] , "Y-m-d H:i:s");
            $aData['expdate'] = $datetimeobj->convert($dateformatdetails['phpdate'].' H:i');
        } else {
            $aData['expdate'] = "-";
        }

        $aData['language'] = getLanguageNameFromCode($oSurvey->language, false);

        if ($oSurvey->currentLanguageSettings->surveyls_urldescription == "") {
            $aSurveyInfo['surveyls_urldescription'] = htmlspecialchars($aSurveyInfo['surveyls_url']);
        }

        if ($oSurvey->currentLanguageSettings->surveyls_url != "") {
            $aData['endurl'] = " <a target='_blank' href=\"".htmlspecialchars($aSurveyInfo['surveyls_url'])."\" title=\"".htmlspecialchars($aSurveyInfo['surveyls_url'])."\">".flattenText($oSurvey->currentLanguageSettings->surveyls_url)."</a>";
        } else {
            $aData['endurl'] = "-";
        }

        $aData['sumcount3'] = $sumcount3;
        $aData['sumcount2'] = $sumcount2;

        if ($activated == "N") {
            $aData['activatedlang'] = gT("No");
        } else {
            $aData['activatedlang'] = gT("Yes");
        }

        $aData['activated'] = $activated;
        if ($oSurvey->isActive) {
            $aData['surveydb'] = Yii::app()->db->tablePrefix."survey_".$iSurveyID;
        }

        $aData['warnings'] = [];
        if ($activated == "N" && $sumcount3 == 0) {
            $aData['warnings'][] = gT("Survey cannot be activated yet.");
            if ($sumcount2 == 0 && Permission::model()->hasSurveyPermission($iSurveyID, 'surveycontent', 'create')) {
                $aData['warnings'][] = "<span class='statusentryhighlight'>[".gT("You need to add question groups")."]</span>";
            }
            if ($sumcount3 == 0 && Permission::model()->hasSurveyPermission($iSurveyID, 'surveycontent', 'create')) {
                $aData['warnings'][] = "<span class='statusentryhighlight'>".gT("You need to add questions")."</span>";
            }
        }
        $aData['hints'] = $surveysummary2;

        //return (array('column'=>array($columns_used,$hard_limit) , 'size' => array($length, $size_limit) ));
        //        $aData['tableusage'] = getDBTableUsage($iSurveyID);
        // ToDo: Table usage is calculated on every menu display which is too slow with big surveys.
        // Needs to be moved to a database field and only updated if there are question/subquestions added/removed (it's currently also not functional due to the port)

        $aData['tableusage'] = false;
        $aData['aAdditionalLanguages'] = $aAdditionalLanguages;
        $aData['groups_count'] = $sumcount2;

        // We get the state of the quickaction
        // If the survey is new (ie: it has no group), it is opened by default
        $quickactionState = SettingsUser::getUserSettingValue('quickaction_state');
        if ($quickactionState === null || $quickactionState === 0) {
            $quickactionState = 1;
            SettingsUser::setUserSetting('quickaction_state', 1);
        }
        $aData['quickactionstate'] = $quickactionState !== null ? intval($quickactionState) : 1;
        $aData['subviewData'] = $aData;

        Yii::app()->getClientScript()->registerPackage('surveysummary');

        /*
        return $aData;

        $content = Yii::app()->getController()->renderPartial("/admin/survey/surveySummary_view", $aData, true);
        Yii::app()->getController()->renderPartial("/admin/super/sidebody", array(
            'content' => $content,
            'sideMenuOpen' => true
        ));
        */
    }

    /**
     * Load survey information based on $action.
     * survey::_fetchSurveyInfo()
     *
     * @param string $action    Given Action
     * @param int    $iSurveyID Given Survey ID
     *
     * @return void | array
     *
     * @deprecated use Survey objects instead
     */
    private function fetchSurveyInfo($action, $iSurveyID = null)
    {
        if ($action == 'newsurvey') {
            $oSurvey = new Survey;
        } elseif ($action == 'editsurvey' && $iSurveyID) {
            $oSurvey = Survey::model()->findByPk($iSurveyID);
        }

        if (isset($oSurvey)) {
            $attribs = $oSurvey->attributes;
            $attribs['googleanalyticsapikeysetting'] = $oSurvey->getGoogleanalyticsapikeysetting();
            return $attribs;
        }
    }

    /**
     * Load "General" tab of new survey screen.
     * survey::_generalTabNewSurvey()
     *
     * @return array
     */
    private function generalTabNewSurvey()
    {
        // use survey option inheritance
        $user = User::model()->findByPk(Yii::app()->session['loginID']);
        $owner = $user->attributes;
        $owner['full_name'] = 'inherit';
        $owner['email'] = 'inherit';
        $owner['bounce_email'] = 'inherit';

        $aData = [];
        $aData['action'] = "newsurvey";
        $aData['owner'] = $owner;
        $aLanguageDetails = getLanguageDetails(Yii::app()->session['adminlang']);
        $aData['sRadixDefault'] = $aLanguageDetails['radixpoint'];
        $aData['sDateFormatDefault'] = $aLanguageDetails['dateformat'];
        $aRadixPointData = [];
        foreach (getRadixPointData() as $index => $radixptdata) {
            $aRadixPointData[$index] = $radixptdata['desc'];
        }
        $aData['aRadixPointData'] = $aRadixPointData;

        foreach (getDateFormatData(0, Yii::app()->session['adminlang']) as $index => $dateformatdata) {
            $aDateFormatData[$index] = $dateformatdata['dateformat'];
        }
        $aData['aDateFormatData'] = $aDateFormatData;

        return $aData;
    }

    /**
     * Returns Data for general template.
     *
     * @param integer $iSurveyID Given Survey ID
     *
     * @return array
     */
    private function getGeneralTemplateData($iSurveyID)
    {
        $aData = [];
        $aData['surveyid'] = $iSurveyID;
        $oSurvey = Survey::model()->findByPk($iSurveyID);
        if (empty($oSurvey)) {
            $oSurvey = new Survey;
        }
        $inheritOwner = empty($oSurvey->oOptions->ownerLabel) ? $oSurvey->owner_id : $oSurvey->oOptions->ownerLabel;
        $users = getUserList();
        $aData['users'] = array();
        $aData['users']['-1'] = gT('Inherit').' ['. $inheritOwner . ']';
        foreach ($users as $user) {
            $aData['users'][$user['uid']] = $user['user'].($user['full_name'] ? ' - '.$user['full_name'] : '');
        }
        // Sort users by name
        asort($aData['users']);
        $aData['aSurveyGroupList'] = SurveysGroups::getSurveyGroupsList();
        return $aData;
    }

    /**
     * Returns data for text edit.
     *
     * @param Survey $survey Given Survey.
     *
     * @return array
     */
    private function getTextEditData($survey)
    {
        Yii::app()->getClientScript()->registerScript(
            "TextEditDataGlobal",
            //todo: this has to be changed, also in frontend packages/textelements/...
            //NEW: connectorBaseUrl: '".Yii::app()->getController()->createUrl('/surveyAdministration/getDateFormatOptions', ['sid' => $survey->sid])."',
            "window.TextEditData = {
                connectorBaseUrl: '".Yii::app()->getController()->createUrl('admin/survey/', ['sid' => $survey->sid, 'sa' => ''])."',
                isNewSurvey: ".($survey->getIsNewRecord() ? "true" : "false").",
                i10N: {
                    'Survey title' : '".gT('Survey title')."',
                    'Date format' : '".gT('Date format')."',
                    'Decimal mark' : '".gT('Decimal mark')."',
                    'End url' : '".gT('End url')."',
                    'URL description (link text)' : '".gT('URL description (link text)')."',
                    'Description' : '".gT('Description')."',
                    'Welcome' : '".gT('Welcome')."',
                    'End message' : '".gT('End message')."'
                }
            };",
            LSYii_ClientScript::POS_BEGIN
        );

        App()->getClientScript()->registerPackage('ace');
        App()->getClientScript()->registerPackage('textelements');
        $aData = $aTabTitles = $aTabContents = array();
        return $aData;
    }

    /**
     * Returns Date for Data Security Edit.
     * tab_edit_view_datasecurity
     * editDataSecurityLocalSettings_view
     *
     * @param Survey $survey Given Survey
     *
     * @return array
     */
    private function getDataSecurityEditData($survey)
    {
        Yii::app()->getClientScript()->registerScript(
            "DataSecTextEditDataGlobal",
            "window.DataSecTextEditData = {
                connectorBaseUrl: '".Yii::app()->getController()->createUrl('admin/survey', ['sid' => $survey->sid, 'sa' => ''])."',
                isNewSurvey: ".($survey->getIsNewRecord() ? "true" : "false").",
                i10N: {
                    'Survey data policy checkbox label:' : '".gT('Survey data policy checkbox label:')."',
                    'Survey data policy error message:' : '".gT('Survey data policy error message:')."',
                    'Survey data policy message:' : '".gT('Survey data policy message:')."',
                    'Don\'t show:' : '".gT('Don\'t show')."',
                    'Inline text' : '".gT('Inline text')."',
                    'Collapsible text' : '".gT('Collapsible text')."',
                    '__INFOTEXT' : '".gT('If you want to specify a link to the survey data policy, 
                    set "Show survey policy text with mandatory checkbox" to "Collapsible text" and use the 
                    placeholders {STARTPOLICYLINK} and {ENDPOLICYLINK} in the "Survey data policy checkbox 
                    label" field to define the link that opens the policy popup. If there is no placeholder given, 
                    there will be an appendix.')."',
                    'Deactivated' : '".gT('Deactivated')."',
                    'Activated' : '".gT('Activated')."'
                }
            };",
            LSYii_ClientScript::POS_BEGIN
        );

        App()->getClientScript()->registerPackage('ace');
        App()->getClientScript()->registerPackage('datasectextelements');
        $aData = $aTabTitles = $aTabContents = array();
        return $aData;
    }

    /**
     * Returns Data for Tab General Edit Survey.
     * survey::_generalTabEditSurvey()
     * Load "General" tab of edit survey screen.
     *
     * @param Survey $survey Given Survey
     *
     * @return mixed
     */
    private function generalTabEditSurvey($survey)
    {
        $aData['survey'] = $survey;
        return $aData;
    }

    /**
     * Returns data for tab Presentation navigation.
     * survey::_tabPresentationNavigation()
     * Load "Presentation & navigation" tab.
     *
     * @param mixed $esrow ?
     *
     * @return array
     */
    private function tabPresentationNavigation($esrow)
    {
        $aData = [];
        $aData['esrow'] = $esrow;
        return $aData;
    }

    /**
     * Returns the data for Tab Publication Access control.
     * survey::_tabPublicationAccess()
     * Load "Publication * access control" tab.
     *
     * @param Survey $survey Given Survey
     *
     * @return array
     */
    private function tabPublicationAccess($survey)
    {
        $aDateFormatDetails = getDateFormatData(Yii::app()->session['dateformat']);
        $aData = [];
        $aData['dateformatdetails'] = $aDateFormatDetails;
        $aData['survey'] = $survey;
        return $aData;
    }

    /**
     * Returns the data for Tab Notification and Data Management.
     * survey::_tabNotificationDataManagement()
     * Load "Notification & data management" tab.
     *
     * @param mixed $esrow ?
     *
     * @return array
     */
    private function tabNotificationDataManagement($esrow)
    {
        $aData = [];
        $aData['esrow'] = $esrow;
        return $aData;
    }

    /**
     * Returns the data for Tab Tokens.
     * survey::_tabTokens()
     * Load "Tokens" tab.
     *
     * @param mixed $esrow ?
     *
     * @return array
     */
    private function tabTokens($esrow)
    {
        $aData = [];
        $aData['esrow'] = $esrow;
        return $aData;
    }

}
