<?php

/**
 * @file QuickSubmitForm.inc.php
 *
 * Copyright (c) 2013-2020 Simon Fraser University
 * Copyright (c) 2003-2020 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class QuickSubmitForm
 *
 * @ingroup plugins_importexport_quickSubmit
 *
 * @brief Form for QuickSubmit one-page submission plugin
 */

namespace APP\plugins\importexport\quickSubmit;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\importexport\quickSubmit\classes\form\SubmissionMetadataForm;
use APP\publication\Publication;
use APP\submission\Submission;
use APP\template\TemplateManager;
use PKP\context\Context;
use PKP\core\Core;
use PKP\core\PKPString;
use PKP\db\DAORegistry;
use PKP\facades\Locale;
use PKP\form\Form;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\security\Role;
use PKP\submission\PKPSubmission;

class QuickSubmitForm extends Form
{
    /** @var Request */
    protected $_request;

    /** @var Submission */
    protected $_submission;

    /** @var Press */
    protected $context;

    /** @var SubmissionMetadataForm */
    protected $_metadataForm;

    /**
     * Constructor
     *
     * @param $plugin object
     * @param $request object
     */
    public function __construct($plugin, $request)
    {
        parent::__construct($plugin->getTemplateResource('index.tpl'));

        $this->_request = $request;
        $this->_context = $request->getContext();

        $this->_metadataForm = new SubmissionMetadataForm($this);

        $locale = $request->getUserVar('locale');
        if ($locale && ($locale != Locale::getLocale())) {
            $this->setDefaultFormLocale($locale);
        }

        if ($submissionId = $request->getUserVar('submissionId')) {
            $this->_submission = Repo::submission()->get($submissionId);
            if ($this->_submission->getContextId() != $this->_context->getId()) {
                throw new Exeption('Submission not in context!');
            }

            $this->_submission->setLocale($this->getDefaultFormLocale());
            $publication = $this->_submission->getCurrentPublication();
            $publication->setData('locale', $this->getDefaultFormLocale());
            $publication->setData('language', PKPString::substr($this->getDefaultFormLocale(), 0, 2));

            $seriesId = $request->getUserVar('seriesId');
            if (!empty($seriesId)) {
                $this->_submission->setSeriesId($seriesId);
            }

            Repo::submission()->edit($this->_submission, []);
            Repo::publication()->edit($publication, []);

            $this->_metadataForm->addChecks($this->_submission);
        }

        $this->addCheck(new \PKP\form\validation\FormValidatorPost($this));
        $this->addCheck(new \PKP\form\validation\FormValidatorCSRF($this));

        // Validation checks for this form
        $supportedSubmissionLocales = $this->_context->getSupportedSubmissionLocales();
        if (!is_array($supportedSubmissionLocales) || count($supportedSubmissionLocales) < 1) {
            $supportedSubmissionLocales = [$this->_context->getPrimaryLocale()];
        }
        $this->addCheck(new \PKP\form\validation\FormValidatorInSet($this, 'locale', 'required', 'submission.submit.form.localeRequired', $supportedSubmissionLocales));

        $this->addCheck(new \PKP\form\validation\FormValidatorUrl($this, 'licenseUrl', 'optional', 'form.url.invalid'));
    }

    /**
     * Get the submission associated with the form.
     *
     * @return Submission
     */
    public function getSubmission()
    {
        return $this->_submission;
    }

    /**
     * Get the names of fields for which data should be localized
     *
     * @return array
     */
    public function getLocaleFieldNames()
    {
        return $this->_metadataForm->getLocaleFieldNames();
    }

    /**
     * Display the form.
     *
     * @param null|mixed $request
     * @param null|mixed $template
     */
    public function display($request = null, $template = null)
    {
        $templateMgr = TemplateManager::getManager($request);

        $templateMgr->assign(
            'supportedSubmissionLocaleNames',
            $this->_context->getSupportedSubmissionLocaleNames()
        );

        // Tell the form what fields are enabled (and which of those are required)
        foreach (Application::getMetadataFields() as $field) {
            $templateMgr->assign([
                $field . 'Enabled' => in_array($this->_context->getData($field), [Context::METADATA_ENABLE, Context::METADATA_REQUEST, Context::METADATA_REQUIRE]),
                $field . 'Required' => $this->_context->getData($field) === Context::METADATA_REQUIRE,
            ]);
        }

        // Cover image delete link action
        $locale = Locale::getLocale();
        $publication = $this->_submission->getCurrentPublication();

        import('lib.pkp.classes.linkAction.LinkAction');
        import('lib.pkp.classes.linkAction.request.AjaxModal');
        $router = $this->_request->getRouter();
        $coverImage = $publication->getLocalizedData('coverImage') ?? '';
        $coverImageName = $coverImage['uploadName'] ?? '';

        $templateMgr->assign('openCoverImageLinkAction', new LinkAction(
            'uploadFile',
            new AjaxModal(
                $router->url($this->_request, null, null, 'importexport', ['plugin', 'QuickSubmitPlugin', 'uploadCoverImage'], [
                    'coverImage' => $coverImageName,
                    'submissionId' => $this->_submission->getId(),
                    'publicationId' => $publication->getId(),
                    // This action can be performed during any stage,
                    // but we have to provide a stage id to make calls
                    // to IssueEntryTabHandler
                    'stageId' => WORKFLOW_STAGE_ID_PRODUCTION,
                ]),
                __('common.upload'),
                'modal_add_file'
            ),
            __('common.upload'),
            'add'
        ));

        $templateMgr->assign('coverImageName', $coverImageName);

        // Get series for this context
        $seriesTitles = Repo::section()
            ->getCollector()
            ->filterByContextIds([$this->_context->getId()])
            ->getMany()
            ->mapWithKeys(function ($series) {
                return [
                    $series->getId() => $series->getLocalizedTitle()
                ];
            })
            ->toArray();
        $seriesOptions = [0 => ''] + $seriesTitles;
        $templateMgr->assign('seriesOptions', $seriesOptions);

        $templateMgr->assign([
            'submission' => $this->_submission,
            'publication' => $publication,
            'locale' => $this->getDefaultFormLocale(),
            'publicationId' => $publication->getId(),
            'licenseUrl' => $this->_context->getData('licenseUrl'),
            'copyrightHolderType' => $this->_context->getData('copyrightHolderType')
        ]);

        // DOI support
        if ($this->_context->areDoisEnabled() && !empty($this->_context->getData(Context::SETTING_DOI_PREFIX))) {
            $enabledDoiTypes = $this->_context->getData(Context::SETTING_ENABLED_DOI_TYPES);
            if (in_array(Repo::doi()::TYPE_PUBLICATION, $enabledDoiTypes)) {
                $templateMgr->assign('assignPublicationDoi', true);
            }
            if (in_array(Repo::doi()::TYPE_CHAPTER, $enabledDoiTypes)) {
                $templateMgr->assign('assignChapterDoi', true);
            }
        }

        // Categories list
        $categoriesOptions = [];
        $categories = Repo::category()->getCollector()
            ->filterByContextIds([$this->_context->getId()])
            ->getMany()
            ->toArray();

        foreach ($categories as $category) {
            $title = $category->getLocalizedTitle();
            if ($category->getParentId()) {
                $title = $categories[$category->getParentId()]->getLocalizedTitle() . ' > ' . $title;
            }
            $categoriesOptions[(int) $category->getId()] = $title;
        }

        $templateMgr->assign([
            'categoriesOptions' => $categoriesOptions,
        ]);

        parent::display($request, $template);
    }

    /**
     * @copydoc Form::validate
     */
    public function validate($callHooks = true)
    {
        if (!parent::validate($callHooks)) {
            return false;
        }
        return true;
    }

    /**
     * Initialize form data for a new form.
     */
    public function initData()
    {
        $this->_data = [];

        if (!$this->_submission) {
            $this->_data['locale'] = $this->getDefaultFormLocale();

            // Create and insert a new submission
            $this->_submission = Repo::submission()->dao->newDataObject();
            $this->_submission->setContextId($this->_context->getId());
            $this->_submission->setStatus(PKPSubmission::STATUS_QUEUED);
            $this->_submission->setSubmissionProgress('start');
            $this->_submission->stampStatusModified();
            $this->_submission->setStageId(WORKFLOW_STAGE_ID_SUBMISSION);
            $this->_submission->setLocale($this->getDefaultFormLocale());

            $publication = new Publication();
            $publication->setData('submissionId', $this->_submission->getId());
            $publication->setData('locale', $this->getDefaultFormLocale());
            $publication->setData('language', PKPString::substr($this->getDefaultFormLocale(), 0, 2));
            $publication->setData('status', PKPSubmission::STATUS_QUEUED);
            $publication->setData('version', 1);

            Repo::submission()->add($this->_submission, $publication, $this->_context);
            $this->_submission = Repo::submission()->get($this->_submission->getId());
            $this->setData('submissionId', $this->_submission->getId());

            $this->_metadataForm->initData($this->_submission);

            // Add the user manager group (first that is found) to the stage_assignment for that submission
            $user = $this->_request->getUser();

            $managerUserGroups = Repo::userGroup()->getCollector()
                ->filterByUserIds([$user->getId()])
                ->filterByContextIds([$this->_context->getId()])
                ->filterByRoleIds([Role::ROLE_ID_MANAGER])
                ->getMany();

            // $userGroupId is being used for $stageAssignmentDao->build
            // This build function needs the userGroupId
            // So here the first function should fail if no manager user group is found.
            $userGroupId = $managerUserGroups->firstOrFail()->getId();

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
    public function readInputData()
    {
        $this->_metadataForm->readInputData();

        $this->readUserVars(
            [
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
            ]
        );
    }

    /**
     * cancel submit
     */
    public function cancel()
    {
        /** @var SubmissionDAO $submissionDao */
        $submissionDao = DAORegistry::getDAO('SubmissionDAO');
        $submission = $submissionDao->getById($this->getData('submissionId'));
        if ($this->_submission->getContextId() != $this->_context->getId()) {
            throw new Exeption('Submission not in context!');
        }
        if ($submission) {
            $submissionDao->deleteById($submission->getId());
        }
    }

    /**
     * Save settings.
     */
    public function execute(...$functionParams)
    {
        // Execute submission metadata related operations.
        $this->_metadataForm->execute($this->_submission, $this->_request);

        $this->_submission->setLocale($this->getData('locale'));
        $this->_submission->setStageId(WORKFLOW_STAGE_ID_PRODUCTION);
        $this->_submission->setDateSubmitted(Core::getCurrentDate());
        $this->_submission->setSubmissionProgress('');
        $this->_submission->setWorkType($this->getData('workType'));

        parent::execute($this->_submission, ...$functionParams);

        Repo::submission()->edit($this->_submission, []);
        $this->_submission = Repo::submission()->get($this->_submission->getId());
        $publication = $this->_submission->getCurrentPublication();

        if ($this->getData('datePublished')) {
            $publication->setData('copyrightYear', date('Y', strtotime($this->getData('datePublished'))));
        }

        if ($this->getData('copyrightHolder') == 'author') {
            $userGroups = Repo::userGroup()->getCollector()
                ->filterByContextIds([$this->_context->getId()])
                ->getMany();
            $publication->setData('copyrightHolder', $publication->getAuthorString($userGroups), $this->getData('locale'));
        } elseif ($this->getData('copyrightHolder') == 'press') {
            $publication->setData('copyrightHolder', $this->_context->getLocalizedData('name'), $this->getData('locale'));
        }

        $publication->setData('licenseUrl', $this->getData('licenseUrl'));

        if ($this->getData('seriesId') && $publication->getData('seriesId') !== (int) $this->getData('seriesId')) {
            $publication = Repo::publication()->edit($publication, ['seriesId' => (int) $this->getData('seriesId')]);
        }

        // Set DOIs
        if ($this->getData('assignPublicationDoi') == 1 || $this->getData('assignChapterDoi') == 1) {
            if ($this->getData('assignPublicationDoi') == 1) {
                $doiId = Repo::doi()->mintPublicationDoi($publication, $this->_submission, $this->_context);
                $publication = Repo::publication()->edit($publication, ['doiId' => $doiId]);
            }

            if ($this->getData('assignChapterDoi') == 1) {
                $chapterDao = DAORegistry::getDAO('ChapterDAO'); /* @var $chapterDao ChapterDAO */
                $chapters = $publication->getData('chapters');
                foreach ($chapters as $chapter) {
                    if (empty($chapter->getData('doiId'))) {
                        $doiId = Repo::doi()->mintChapterDoi($chapter, $this->_submission, $this->_context);
                        $chapter->setData('doiId', $doiId);
                        $chapterDao->updateObject($chapter);
                    }
                }
            }
        }

        // Save the submission categories
        $publication->setData('categoryIds', $this->getData('categories'));

        // Update publication
        Repo::publication()->edit($publication, []);

        // If publish now, set date and publish publication
        if ($this->getData('submissionStatus') == 1) {
            $publication->setData('datePublished', $this->getData('datePublished'));
            Repo::publication()->publish($publication);
        }

        // Index monograph.
        $submissionSearchIndex = Application::getSubmissionSearchIndex();
        $submissionSearchIndex->submissionMetadataChanged($this->_submission);
        $submissionSearchIndex->submissionFilesChanged($this->_submission);
        $submissionSearchIndex->submissionChangesFinished();
    }
}
