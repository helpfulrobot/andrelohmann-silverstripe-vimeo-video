<?php
/**
 * Restart processing of all failed vimeo video files
 *
 * @package framework
 * @subpackage filesystem
 */
class RestartFailedVimeoVideoFiles extends BuildTask {

	protected $title = 'Restart processing of all failed vimeo video files';

	protected $description = 'Restart processing of all failed VimeoVideoFile objects';

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
		$failedFiles = 0;
		$Videos = VimeoVideoFile::get()->filter(array('VimeoProcessingStatus' => array('error', 'processingerror')));

		foreach($Videos as $vid){
			
			$failedFiles++;
			
			if($vid->ProcessingStatus == 'error') $vid->ProcessingStatus = 'new';
			$vid->VimeoProcessingStatus = 'unprocessed';
			$vid->write();
			
			$vid->onAfterLoad();
			
			sleep(5);
		}

		echo "$failedFiles failed VimeoVideoFile objects have reinitiated the processing.";
	}

}
