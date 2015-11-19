<?php
/**
 * Finish Processing Files and refetch all necessary information
 *
 * @package framework
 * @subpackage filesystem
 */
class FinishProcessingVimeoVideoFiles extends BuildTask {

	protected $title = 'Finish processing vimeo video files';

	protected $description = 'Videofiles are set to "processing", while vimeo is doing the postprocessing. Fetching of the Post processed file information needs to be initiated by the application, as vimeo does not offer any callbacks. This task will do a manual fetch of the processed information.';

	/**
	 * Check that the user has appropriate permissions to execute this task
	 */
	public function init() {
		if(!Director::is_cli() && !Director::isDev() && !Permission::check('ADMIN')) {
			return Security::permissionFailure();
		}

		parent::init();
	}

	/**
	 * Clear out the image manipulation cache
	 * @param SS_HTTPRequest $request
	 */
	public function run($request) {
		$processingFiles = 0;
		$processedFiles = 0;
		$VimeoVideos = VimeoVideoFile::get()->filter(array('VimeoProcessingStatus' => 'processing'));

		foreach($VimeoVideos as $vid){
			
			$processingFiles++;
			
			if($vid->IsProcessed()) $processedFiles++;
			
			sleep(5);
		}

		echo "$processedFiles of $processingFiles processing files are now processed.";
	}

}
