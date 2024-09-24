<?php

/**
 * @file QuickSubmitForm.inc.php
 *
 * Copyright (c) 2013-2020 Simon Fraser University
 * Copyright (c) 2003-2020 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class QuickSubmitForm
 * @ingroup plugins_importexport_quickSubmit
 *
 * @brief Form for QuickSubmit one-page submission plugin
 */


import('lib.pkp.classes.form.Form');
import('classes.submission.SubmissionMetadataFormImplementation');
import('classes.publication.Publication');

class QuickSubmitForm extends Form {
	/** @var Request */
	protected $_request;

	/** @var Submission */
	protected $_submission;

	/** @var Press */
	protected $context;

	/** @var SubmissionMetadataFormImplementation */
	protected $_metadataFormImplem;

	/**
	 * Constructor
	 * @param $plugin object
	 * @param $request object
	 */
	function __construct($plugin, $request) {
		parent::__construct($plugin->getTemplateResource('index.tpl'));

		$this->_request = $request;
		$this->_context = $request->getContext();

		$this->_metadataFormImplem = new SubmissionMetadataFormImplementation($this);

		$locale = $request->getUserVar('locale');
		if ($locale && ($locale != AppLocale::getLocale())) {
			$this->setDefaultFormLocale($locale);
		}

		if ($submissionId = $request->getUserVar('submissionId')) {
			$submissionDao = DAORegistry::getDAO('SubmissionDAO'); /* @var SubmissionDAO $submissionDao */
			$publicationDao = DAORegistry::getDAO('PublicationDAO'); /* @var $publicationDao PublicationDAO */

			$this->_submission = $submissionDao->getById($submissionId);
			if ($this->_submission->getContextId() != $this->_context->getId()) throw new Exeption('Submission not in context!');

			$this->_submission->setLocale($this->getDefaultFormLocale());
			$publication = $this->_submission->getCurrentPublication();
			$publication->setData('locale', $this->getDefaultFormLocale());
			$publication->setData('language', PKPString::substr($this->getDefaultFormLocale(), 0, 2));

			$seriesId = $request->getUserVar('seriesId');
			if (!empty($seriesId)) {
				$this->_submission->setSeriesId($seriesId);
			}

			$submissionDao->updateObject($this->_submission);
			$publicationDao->updateObject($publication);

			$this->_metadataFormImplem->addChecks($this->_submission);
		}

		$this->addCheck(new FormValidatorPost($this));
		$this->addCheck(new FormValidatorCSRF($this));

		// Validation checks for this form
		$supportedSubmissionLocales = $this->_context->getSupportedSubmissionLocales();
		if (!is_array($supportedSubmissionLocales) || count($supportedSubmissionLocales) < 1)
			$supportedSubmissionLocales = array($this->_context->getPrimaryLocale());
		$this->addCheck(new FormValidatorInSet($this, 'locale', 'required', 'submission.submit.form.localeRequired', $supportedSubmissionLocales));

		$this->addCheck(new FormValidatorURL($this, 'licenseUrl', 'optional', 'form.url.invalid'));
	}

	/**
	 * Get the submission associated with the form.
	 * @return Submission
	 */
	function getSubmission() {
		return $this->_submission;
	}

	/**
	 * Get the names of fields for which data should be localized
	 * @return array
	 */
	function getLocaleFieldNames() {
		return $this->_metadataFormImplem->getLocaleFieldNames();
	}

	/**
	 * Display the form.
	 */
	function display($request = null, $template = null) {
		$templateMgr = TemplateManager::getManager($request);

		$templateMgr->assign(
			'supportedSubmissionLocaleNames',
			$this->_context->getSupportedSubmissionLocaleNames()
		);

		// Tell the form what fields are enabled (and which of those are required)
		foreach (Application::getMetadataFields() as $field) {
			$templateMgr->assign(array(
				$field . 'Enabled' => in_array($this->_context->getData($field), array(METADATA_ENABLE, METADATA_REQUEST, METADATA_REQUIRE)),
				$field . 'Required' => $this->_context->getData($field) === METADATA_REQUIRE,
			));
		}

		// Cover image delete link action
		$locale = AppLocale::getLocale();
		$publication = $this->_submission->getCurrentPublication();

		import('lib.pkp.classes.linkAction.LinkAction');
		import('lib.pkp.classes.linkAction.request.AjaxModal');
		$router = $this->_request->getRouter();
		$coverImage = $publication->getLocalizedData('coverImage') ?? '';
		$coverImageName = $coverImage['uploadName'] ?? '';

		$templateMgr->assign('openCoverImageLinkAction', new LinkAction(
			'uploadFile',
			new AjaxModal(
				$router->url($this->_request, null, null, 'importexport', array('plugin', 'QuickSubmitPlugin', 'uploadCoverImage'), array(
					'coverImage' => $coverImageName,
					'submissionId' => $this->_submission->getId(),
					'publicationId' => $publication->getId(),
					// This action can be performed during any stage,
					// but we have to provide a stage id to make calls
					// to IssueEntryTabHandler
					'stageId' => WORKFLOW_STAGE_ID_PRODUCTION,
				)),
				__('common.upload'),
				'modal_add_file'
			),
			__('common.upload'),
			'add'
		));

		$templateMgr->assign('coverImageName', $coverImageName);

		// Get series for this context
		$seriesDao = DAORegistry::getDAO('SeriesDAO');
		$seriesOptions = array('0' => '') + $seriesDao->getTitlesByContextId($this->_context->getId());
		$templateMgr->assign('seriesOptions', $seriesOptions);

		$templateMgr->assign(array(
			'submission' => $this->_submission,
			'publication' => $publication,
			'locale' => $this->getDefaultFormLocale(),
			'publicationId' => $publication->getId(),
			'licenseUrl' => $this->_context->getData('licenseUrl'),
			'copyrightHolderType' => $this->_context->getData('copyrightHolderType')
		));

		// DOI support
		$pubIdPlugins = PluginRegistry::loadCategory('pubIds', true, $this->_context->getId());
		$doiPubIdPlugin = $pubIdPlugins['doipubidplugin'];
		if ($doiPubIdPlugin && $doiPubIdPlugin->getSetting($this->_context->getId(), 'doiPrefix')){
			if ($doiPubIdPlugin->getSetting($this->_context->getId(), 'enablePublicationDoi')) {
				$templateMgr->assign('assignPublicationDoi', true);
			}
			if ($doiPubIdPlugin->getSetting($this->_context->getId(), 'enableChapterDoi')) {
				$templateMgr->assign('assignChapterDoi', true);
			}
		}
		// DOI support

		// Categories list
		$categoryDao = DAORegistry::getDAO('CategoryDAO'); /* @var $categoryDao CategoryDAO */
		$categoriesOptions = [];
		$categories = $categoryDao->getByContextId($this->_context->getId())->toAssociativeArray();
		foreach ($categories as $category) {
			$title = $category->getLocalizedTitle();
			if ($category->getParentId()) {
				$title = $categories[$category->getParentId()]->getLocalizedTitle() . ' > ' . $title;
			}
			$categoriesOptions[(int) $category->getId()] = $title;
		}

		$templateMgr->assign(array(
			'categoriesOptions' => $categoriesOptions,
		));

		parent::display($request, $template);
	}

	/**
	 * @copydoc Form::validate
	 */
	function validate($callHooks = true) {
		if (!parent::validate($callHooks)) return false;
		return true;
	}

	/**
	 * Initialize form data for a new form.
	 */
	function initData() {
		$this->_data = array();

		if (!$this->_submission) {
			$this->_data['locale'] = $this->getDefaultFormLocale();

			// Get Series
			$seriesDao = DAORegistry::getDAO('SeriesDAO');
			$seriesOptions = $seriesDao->getTitlesByContextId($this->_context->getId());

			// Create and insert a new submission
			/** @var SubmissionDAO $submissionDao */
			$submissionDao = DAORegistry::getDAO('SubmissionDAO');
			$this->_submission = $submissionDao->newDataObject();
			$this->_submission->setContextId($this->_context->getId());
			$this->_submission->setStatus(STATUS_QUEUED);
			$this->_submission->setSubmissionProgress(1);
			$this->_submission->stampStatusModified();
			$this->_submission->setStageId(WORKFLOW_STAGE_ID_SUBMISSION);
			$this->_submission->setData('seriesId', $seriesId = current(array_keys($seriesOptions)));
			$this->_submission->setLocale($this->getDefaultFormLocale());

			// Insert the submission
			$this->_submission = Services::get('submission')->add($this->_submission, $this->_request);
			$this->setData('submissionId', $this->_submission->getId());

			$publication = new Publication();
			$publication->setData('submissionId', $this->_submission->getId());
			$publication->setData('locale', $this->getDefaultFormLocale());
			$publication->setData('language', PKPString::substr($this->getDefaultFormLocale(), 0, 2));
			$publication->setData('seriesId', $seriesId);
			$publication->setData('status', STATUS_QUEUED);
			$publication->setData('version', 1);
			$publication = Services::get('publication')->add($publication, $this->_request);
			$this->_submission = Services::get('submission')->edit($this->_submission, ['currentPublicationId' => $publication->getId()], $this->_request);

			$this->_metadataFormImplem->initData($this->_submission);

			// Add the user manager group (first that is found) to the stage_assignment for that submission
			$user = $this->_request->getUser();

			$userGroupAssignmentDao = DAORegistry::getDAO('UserGroupAssignmentDAO');
			$userGroupDao = DAORegistry::getDAO('UserGroupDAO');

			$userGroupId = null;
			$managerUserGroupAssignments = $userGroupAssignmentDao->getByUserId($user->getId(), $this->_context->getId(), ROLE_ID_MANAGER);
			if($managerUserGroupAssignments) {
				while($managerUserGroupAssignment = $managerUserGroupAssignments->next()) {
					$managerUserGroup = $userGroupDao->getById($managerUserGroupAssignment->getUserGroupId());
					$userGroupId = $managerUserGroup->getId();
					break;
				}
			}

			// Pre-fill the copyright information fields from setup (#7236)
			$this->_data['licenseUrl'] = $this->_context->getData('licenseUrl');
			switch ($this->_context->getData('copyrightHolderType')) {
			case 'author':
				// The author has not been entered yet; let the user fill it in.
				break;
			case 'context':
				$this->_data['copyrightHolder'] = $this->_context->getData('name');
				break;
			case 'other':
				$this->_data['copyrightHolder'] = $this->_context->getData('copyrightHolderOther');
				break;
			}
			$this->_data['copyrightYear'] = date('Y');

			// Assign the user author to the stage
			$stageAssignmentDao = DAORegistry::getDAO('StageAssignmentDAO');
			$stageAssignmentDao->build($this->_submission->getId(), $userGroupId, $user->getId());
		}
	}

	/**
	 * Assign form data to user-submitted data.
	 */
	function readInputData() {
		$this->_metadataFormImplem->readInputData();

		$this->readUserVars(
			array(
				'datePublished',
				'licenseUrl',
				'copyrightHolder',
				'copyrightYear',
				'seriesId',
				'categories',
				'workType',
				'submissionId',
				'submissionStatus',
				'locale',
				'assignPublicationDoi',
				'assignChapterDoi',
			)
		);
	}

	/**
	 * cancel submit
	 */
	function cancel() {
		/** @var SubmissionDAO $submissionDao */
		$submissionDao = DAORegistry::getDAO('SubmissionDAO');
		$submission = $submissionDao->getById($this->getData('submissionId'));
		if ($this->_submission->getContextId() != $this->_context->getId()) throw new Exeption('Submission not in context!');
		if ($submission) $submissionDao->deleteById($submission->getId());
	}

	/**
	 * Save settings.
	 */
	function execute(...$functionParams) {
		// Execute submission metadata related operations.
		$this->_metadataFormImplem->execute($this->_submission, $this->_request);

		$this->_submission->setLocale($this->getData('locale'));
		$this->_submission->setStageId(WORKFLOW_STAGE_ID_PRODUCTION);
		$this->_submission->setDateSubmitted(Core::getCurrentDate());
		$this->_submission->setSubmissionProgress(0);
		$this->_submission->setWorkType($this->getData('workType'));

		parent::execute($this->_submission, ...$functionParams);

		$submissionDao = DAORegistry::getDAO('SubmissionDAO'); /* @var $submissionDao SubmissionDAO */
		$submissionDao->updateObject($this->_submission);
		$this->_submission = $submissionDao->getById($this->_submission->getId());

		$publication = $this->_submission->getCurrentPublication();

		if ($this->getData('datePublished')){
			$publication->setData('copyrightYear', date("Y", strtotime($this->getData('datePublished'))));
		}

		if ($this->getData('copyrightHolder') == 'author') {
			$userGroupDao = DAORegistry::getDAO('UserGroupDAO'); /* @var $userGroupDao UserGroupDAO */
			$userGroups = $userGroupDao->getByContextId($this->_context->getId())->toArray();
			$publication->setData('copyrightHolder', $publication->getAuthorString($userGroups), $this->getData('locale'));
		} elseif ($this->getData('copyrightHolder') == 'press') {
			$publication->setData('copyrightHolder', $this->_context->getLocalizedData('name'), null);
		}

		$publication->setData('licenseUrl', $this->getData('licenseUrl'));

		if ($publication->getData('seriesId') !== (int) $this->getData('seriesId')) {
			$publication->setData('seriesId', $this->getData('seriesId'));
		}

		// Set DOIs
		if ($this->getData('assignPublicationDoi') == 1 || $this->getData('assignChapterDoi') == 1){
			$pubIdPlugins = PluginRegistry::loadCategory('pubIds', true, $this->_context->getId());
			$doiPubIdPlugin = $pubIdPlugins['doipubidplugin'];
			$pubIdPrefix = $doiPubIdPlugin->getSetting($this->_context->getId(), 'doiPrefix');
			$suffixGenerationStrategy = $doiPubIdPlugin->getSetting($this->_context->getId(), $doiPubIdPlugin->getSuffixFieldName(), $publication);

			if ($this->getData('assignPublicationDoi') == 1) {
				$pubIdSuffix = $this->getDoiSuffix($suffixGenerationStrategy, $doiPubIdPlugin, $publication, $this->_context, $this->_submission, null);
				$pubId = $doiPubIdPlugin->constructPubId($pubIdPrefix, $pubIdSuffix, $this->_context->getId());
				$publication->setData('pub-id::doi', $pubId);
			}

			if ($this->getData('assignChapterDoi') == 1) {
				$chapterDao = DAORegistry::getDAO('ChapterDAO'); /* @var $chapterDao ChapterDAO */
				$chapters = $publication->getData('chapters');
				foreach ($chapters as $chapter) {
					$pubIdSuffix = $this->getDoiSuffix($suffixGenerationStrategy, $doiPubIdPlugin, $chapter, $this->_context, $this->_submission, $chapter);
					$pubId = $doiPubIdPlugin->constructPubId($pubIdPrefix, $pubIdSuffix, $this->_context->getId());
					$chapter->setData('pub-id::doi', $pubId);
					$chapterDao->updateObject($chapter);
				}
			}
		}

		// Save the submission categories
		$categoryDao = DAORegistry::getDAO('CategoryDAO'); /* @var $categoryDao CategoryDAO */
		$categoryDao->deletePublicationAssignments($publication->getId());
		if ($categories = $this->getData('categories')) {
			foreach ((array) $categories as $categoryId) {
				$categoryDao->insertPublicationAssignment($categoryId, $publication->getId());
			}
		}

		// If publish now, set date and publish publication
		if ($this->getData('submissionStatus') == 1) {
			$publication->setData('datePublished', $this->getData('datePublished'));
			$publication = Services::get('publication')->publish($publication);
		}

		// Update publication
		$publicationDao = DAORegistry::getDAO('PublicationDAO'); /* @var $publicationDao PublicationDAO */
		$publicationDao->updateObject($publication);

		// Index monograph.
		$submissionSearchIndex = Application::getSubmissionSearchIndex();
		$submissionSearchIndex->submissionMetadataChanged($this->_submission);
		$submissionSearchIndex->submissionFilesChanged($this->_submission);
		$submissionSearchIndex->submissionChangesFinished();

	}

	function getDoiSuffix($suffixGenerationStrategy, $doiPubIdPlugin, $pubObject, $context, $submission, $chapter){
		switch ($suffixGenerationStrategy) {
			case 'customId':
				$pubIdSuffix = $pubObject->getData('doiSuffix');
				break;

			case 'pattern':
				$suffixPatternsFieldNames = $doiPubIdPlugin->getSuffixPatternsFieldNames();
				$pubIdSuffix = $doiPubIdPlugin->getSetting($context->getId(), $suffixPatternsFieldNames[$doiPubIdPlugin->getPubObjectType($pubObject)]);

				// %p - press initials
				$pubIdSuffix = PKPString::regexp_replace('/%p/', PKPString::strtolower($context->getAcronym($context->getPrimaryLocale())), $pubIdSuffix);

				// %x - custom identifier
				if ($pubObject->getStoredPubId('publisher-id')) {
					$pubIdSuffix = PKPString::regexp_replace('/%x/', $pubObject->getStoredPubId('publisher-id'), $pubIdSuffix);
				}

				if ($submission) {
					// %m - monograph id
					$pubIdSuffix = PKPString::regexp_replace('/%m/', $submission->getId(), $pubIdSuffix);
				}

				if ($chapter) {
					// %c - chapter id
					$pubIdSuffix = PKPString::regexp_replace('/%c/', $chapter->getId(), $pubIdSuffix);
				}

				break;

			default:
				$pubIdSuffix = PKPString::strtolower($context->getAcronym($context->getPrimaryLocale()));

				if ($submission) {
					$pubIdSuffix .= '.' . $submission->getId();
				}

				if ($chapter) {
					$pubIdSuffix .= '.c' . $chapter->getId();
				}
		}

		return $pubIdSuffix;
	}

}

