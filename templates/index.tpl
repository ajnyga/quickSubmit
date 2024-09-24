{**
 * templates/index.tpl
 *
 * Copyright (c) 2013-2020 Simon Fraser University
 * Copyright (c) 2003-2020 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * Template for one-page submission form
 *}
{extends file="layouts/backend.tpl"}

{block name="page"}
	<h1 class="app__pageHeading">
		{translate key="plugins.importexport.quickSubmit.displayName"}
	</h1>

	<script src="{$baseUrl}/plugins/importexport/quickSubmit/js/QuickSubmitFormHandler.js"></script>

	<script>
		$(function() {ldelim}
			// Attach the form handler.
			$('#quickSubmitForm').pkpHandler('$.pkp.plugins.importexport.quickSubmit.js.QuickSubmitFormHandler');
		{rdelim});
	</script>

	<div id="quickSubmitPlugin" class="app__contentPanel pkp_pageQuickSubmit">
		<p>{translate key="plugins.importexport.quickSubmit.descriptionLong"}</p>

		<form class="pkp_form" id="quickSubmitForm" method="post" action="{plugin_url path="saveSubmit"}">
			<input type="hidden" name="reloadForm" id="reloadForm" value="0" />

			{if $submissionId}
				<input type="hidden" name="submissionId" value="{$submissionId|escape}" />
			{/if}
			{if $issuesPublicationDates}
				{fbvElement type="hidden" id="issuesPublicationDates" value=$issuesPublicationDates}
			{/if}

			{csrf}
			{include file="controllers/notification/inPlaceNotification.tpl" notificationId="quickSubmitFormNotification"}

			{* There is only one supported submission locale; choose it invisibly *}
			{if count($supportedSubmissionLocaleNames) == 1}
				{foreach from=$supportedSubmissionLocaleNames item=localeName key=locale}
					{fbvElement type="hidden" id="locale" value=$locale}
				{/foreach}

			{* There are several submission locales available; allow choice *}
			{else}
				{fbvFormSection title="submission.submit.submissionLocale" size=$fbvStyles.size.MEDIUM for="locale"}
					{fbvElement label="submission.submit.submissionLocaleDescription" required="true" type="select" id="locale" from=$supportedSubmissionLocaleNames selected=$locale translate=false}
				{/fbvFormSection}
			{/if}

			{fbvFormSection label="monograph.coverImage" class=$wizardClass}
				{if $coverImageName != ''}
					<div class="img">
						<img src="{$publicFilesDir}/{$coverImageName|escape:"url"}{'?'|uniqid}">
					</div>
				{/if}
				<div id="{$openCoverImageLinkAction->getId()}" class="pkp_linkActions">
					{include file="linkAction/linkAction.tpl" action=$openCoverImageLinkAction contextId="quickSubmitForm"}
				</div>
			{/fbvFormSection}

			{fbvFormSection list="true" label="submission.workflowType" description="submission.workflowType.description"}
				{fbvElement type="radio" name="workType" id="isEditedVolume-0" value=$smarty.const.WORK_TYPE_AUTHORED_WORK checked=$workType|compare:$smarty.const.WORK_TYPE_EDITED_VOLUME:false:true label="submission.workflowType.authoredWork"}
				{fbvElement type="radio" name="workType" id="isEditedVolume-1" value=$smarty.const.WORK_TYPE_EDITED_VOLUME checked=$workType|compare:$smarty.const.WORK_TYPE_EDITED_VOLUME label="submission.workflowType.editedVolume"}
			{/fbvFormSection}

			{include file="submission/form/series.tpl" readOnly=$formParams.readOnly}

			{if count($categoriesOptions)}
				{fbvFormSection list=true title="grid.category.categories"}
					{foreach from=$categoriesOptions item="category" key="id"}
						{if $categories}
							{fbvElement type="checkbox" id="categories[]" value=$id checked=in_array($id, $categories) label=$category|escape translate=false}
						{else}
							{fbvElement type="checkbox" id="categories[]" value=$id label=$category|escape translate=false}
						{/if}
					{/foreach}
				{/fbvFormSection}
			{/if}

			{include file="core:submission/submissionMetadataFormTitleFields.tpl"}

			{include file="submission/submissionMetadataFormFields.tpl"}

			{fbvFormArea id="contributors"}
				<!--  Contributors -->
				{capture assign="authorGridUrl"}{url router=$smarty.const.ROUTE_COMPONENT component="grid.users.author.AuthorGridHandler" op="fetchGrid" submissionId=$submissionId publicationId=$publicationId escape=false}{/capture}
				{load_url_in_div id="authorsGridContainer" url=$authorGridUrl}

				{$additionalContributorsFields}
			{/fbvFormArea}

			{fbvFormArea id="representations-grid"}
				{capture assign="representationsGridUrl"}{url router=$smarty.const.ROUTE_COMPONENT component="grid.catalogEntry.PublicationFormatGridHandler" op="fetchGrid" submissionId=$submissionId publicationId=$publicationId escape=false}{/capture}
				{load_url_in_div id="formatsGridContainer"|uniqid url=$representationsGridUrl}
			{/fbvFormArea}

			{fbvFormArea id="chapters"}
				{capture assign=chaptersGridUrl}{url router=$smarty.const.ROUTE_COMPONENT  component="grid.users.chapter.ChapterGridHandler" op="fetchGrid" submissionId=$submissionId publicationId=$publicationId escape=false}{/capture}
				{load_url_in_div id="chaptersGridContainer" url=$chaptersGridUrl}
			{/fbvFormArea}

			{if $pubIds}
				{foreach from=$pubIdPlugins item=pubIdPlugin}
					{assign var=pubIdAssignFile value=$pubIdPlugin->getPubIdAssignFile()}
					{assign var=canBeAssigned value=$pubIdPlugin->canBeAssigned($publication)}
					{include file="$pubIdAssignFile" pubIdPlugin=$pubIdPlugin pubObject=$publication canBeAssigned=$canBeAssigned}
				{/foreach}
			{/if}

			{if $assignPublicationDoi || $assignChapterDoi}
				{fbvFormArea id="addDois"}
					{fbvFormSection list="true"}
					{if $assignPublicationDoi}
						{fbvElement type="checkbox" id="assignPublicationDoi" value="1" checked=$assignPublicationDoi label="plugins.importexport.quickSubmit.assignPublicationDoi"}
					{/if}
					{if $assignChapterDoi}
						{fbvElement type="checkbox" id="assignChapterDoi" value="1" checked=$assignChapterDoi label="plugins.importexport.quickSubmit.assignChapterDoi"}
					{/if}
					{/fbvFormSection}
				{/fbvFormArea}
			{/if}

			{fbvFormArea id="permissions"}
				{fbvFormSection list="true" label="submission.licenseURL"}
					{fbvElement type="text" id="licenseUrl" value=$licenseUrl}
				{/fbvFormSection}
				{fbvFormSection list="true" label="submission.copyrightHolder"}
					{fbvElement type="radio" name="copyrightHolder" id="copyrightHolder-0" value="author" checked=$copyrightHolderType|compare:author label="user.role.author"}
					{fbvElement type="radio" name="copyrightHolder" id="copyrightHolder-1" value="press" checked=$copyrightHolderType|compare:context label="context.context"}
				{/fbvFormSection}
			{/fbvFormArea}

			{* Publishing monograph section *}
				{fbvFormSection id='submissionPublishingSection' list="false"}
					{fbvElement type="radio" id="submissionUnpublished" name="submissionStatus" value=0 checked=$submissionStatus|compare:false label='plugins.importexport.quickSubmit.unpublished' translate="true"}
					{fbvElement type="radio" id="submissionPublished" name="submissionStatus" value=1 checked=$submissionStatus|compare:true label='plugins.importexport.quickSubmit.published' translate="true"}

					{fbvFormSection id='schedulePublicationDiv' list="false"}
						{fbvFormArea id="schedulingInformationDatePublished" title="publication.datePublished"}
							{fbvFormSection for="publishedDate"}
								{fbvElement type="text" required=true id="datePublished" value=$datePublished translate=false label="publication.datePublished" inline=true size=$fbvStyles.size.MEDIUM class="datepicker"}
							{/fbvFormSection}
						{/fbvFormArea}
					{/fbvFormSection}

				{/fbvFormSection}

			{capture assign="cancelUrl"}{plugin_url path="cancelSubmit" submissionId="$submissionId"}{/capture}

			{fbvFormButtons id="quickSubmit" submitText="common.save" cancelUrl=$cancelUrl cancelUrlTarget="_self"}
		</form>
	</div>
{/block}
