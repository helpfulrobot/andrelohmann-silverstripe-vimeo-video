<?php
/**
 * update information of all processed vimeo video files
 *
 * @package framework
 * @subpackage filesystem
 */
class UpdateVimeoVideoFiles extends BuildTask {

	protected $title = 'Update Vimeo Video Files';

	protected $description = 'Update information of all processed VimeoVideoFile Objects. !!! This will be a time intensive task !!!';

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
		$updatedFiles = 0;
		$VimeoVideos = VimeoVideoFile::get()->filter(array('VimeoProcessingStatus' => 'finished'));

		foreach($VimeoVideos as $vid){
			
			$updatedFiles++;
			
			if($vid->IsProcessed()) $processedFiles++;
			
			sleep(5);
		}

		echo "$processedFiles of $processingFiles processing files are now processed.";
	}

}
