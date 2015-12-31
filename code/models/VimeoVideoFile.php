<?php

class VimeoVideoFile extends VideoFile
{

    
    private static $client_id = null;
    private static $client_secret = null;
    private static $access_token = null;
    private static $api_request_time = 900;
    
    private static $album_id = null;
    private static $preset_id = null;
    
    /**
     * @config
     * @var array List of allowed file extensions, enforced through {@link validate()}.
     * 
     * Note: if you modify this, you should also change a configuration file in the assets directory.
     * Otherwise, the files will be able to be uploaded but they won't be able to be served by the
     * webserver.
     * 
     *  - If you are running Apahce you will need to change assets/.htaccess
     *  - If you are running IIS you will need to change assets/web.config 
     *
     * Instructions for the change you need to make are included in a comment in the config file.
     */
    //private static $allowed_extensions = array(
    //	'avi','flv','m4v','mov','mp4','mpeg','mpg','ogv','webm','wmv'
    //);

    //const VIMEO_ERROR_

    private static $db = array(
        'VimeoProcessingStatus' => "Enum(array('unprocessed','uploading','updating','processing','processingerror','error','finished'))", // uploading, processing, finished
        'VimeoURI'   =>  'Varchar(255)',
        'VimeoLink'  =>  'Varchar(255)',
        'VimeoID' => 'Varchar(255)',
        'VimeoHLSUrl' => 'Varchar(255)',
        'VimeoHLSUrlSecure' => 'Varchar(255)',
        'VimeoFullHDUrl' => 'Varchar(255)', // 1080p
        'VimeoFullHDUrlSecure' => 'Varchar(255)', // 1080p
        'VimeoHDUrl' => 'Varchar(255)', // 720p
        'VimeoHDUrlSecure' => 'Varchar(255)', // 720p
        'VimeoSDUrl' => 'Varchar(255)', // 360p
        'VimeoSDUrlSecure' => 'Varchar(255)', // 360p
        'VimeoPicturesURI'  =>  'Varchar(255)'
    );
    
    private static $defaults = array(
        'VimeoProcessingStatus' => 'unprocessed'
    );
    
    protected function getLogFile()
    {
        if (!$this->log_file) {
            $this->log_file = TEMP_FOLDER.'/VimeoVideoFileProcessing-ID-'.$this->ID.'-'.md5($this->getRelativePath()).'.log';
        }
        return $this->log_file;
    }

    public function process($LogFile = false, $runAfterProcess = true)
    {
        if (!$LogFile) {
            $LogFile = $this->getLogFile();
        }
        
        switch ($this->ProcessingStatus) {
            case 'new':
                if (parent::process($LogFile, $runAfterProcess)) {
                    $this->vimeoProcess($LogFile, $runAfterProcess);
                } else {
                    // Something went wrong
                }
            break;
            
            case 'finished':
                $this->vimeoProcess($LogFile, $runAfterProcess);
            break;
            
            case 'processing':
                // just do nothing
            break;
        
            case 'error':
                // just do nothing
            break;
        }
    }
        
    protected function vimeoProcess($LogFile, $runAfterProcess = true)
    {
        $this->appendLog($LogFile, "vimeoProcess() started");
        
        switch ($this->VimeoProcessingStatus) {
            case 'processingerror':
            case 'unprocessed':
                
                // upload the Video
                $this->vimeoUpload($LogFile);
                
                if ($this->VimeoProcessingStatus == 'finished' && $runAfterProcess) {
                    $this->onAfterProcess();
                }
                
            break;
            
            case 'uploading':
                // just do nothing
            break;
        
            case 'processing':
                // just do nothing
            break;
        
            case 'updating':
                // just do nothing
            break;
        
            case 'error':
                // just do nothing
            break;
        
            case 'finished':
                // just do nothing
            break;
        }
    }
    
    protected function vimeoUpload($LogFile)
    {
        $this->VimeoProcessingStatus = 'uploading';
        $this->write();
        
        try {
            $this->appendLog($LogFile, "vimeoUpload() started");
            
            if ($lib = new \Vimeo\Vimeo(Config::inst()->get('VimeoVideoFile', 'client_id'), Config::inst()->get('VimeoVideoFile', 'client_secret'), Config::inst()->get('VimeoVideoFile', 'access_token'))) {
                // Send a request to vimeo for uploading the new video
                if ($this->VimeoURI) {
                    $uri = $lib->request($this->VimeoURI, array(), 'DELETE');
                    $this->VimeoURI = null;
                    $this->VimeoLink = null;
                    $this->VimeoID = null;
                    $this->write();
                }
                
                $uri = $lib->upload($this->getFullPath(), false);
                //$uri = "/videos/134840586";
                $video_data = $lib->request($uri);
                
                $this->appendLog($LogFile, "Vimeo Video Data returned", print_r($video_data, true));
                
                if ($video_data['status'] == 200) {
                    $this->VimeoURI = $video_data['body']['uri'];
                    $this->VimeoLink = $video_data['body']['link'];
                    $id = explode('/', $video_data['body']['uri']);
                    $this->VimeoID = $id[count($id)-1];
                    $this->write();
                    
                    $this->appendLog($LogFile, "File uploaded to Vimeo");

                    if ($video_data['body']['status'] == 'available') {
                        return $this->extractUrls($video_data);
                    } elseif ($video_data['body']['status'] == 'uploading' || $video_data['body']['status'] == 'transcoding') {
                        $this->VimeoProcessingStatus = 'processing';
                        $this->write();
                        return false;
                    } else {
                        // quota_exceeded, uploading_error, transcoding_error => processingerror
                        $this->VimeoProcessingStatus = 'processingerror';
                        $this->write();
                        return false;
                    }
                } else {
                    $this->appendLog($LogFile, "Error in Upload", print_r($video_data, true));
                    
                    $this->VimeoProcessingStatus = 'unprocessed';
                    $this->write();
                    return false;
                }
            } else {
                $this->appendLog($LogFile, "Missing clientID or clientSecret");
                
                $this->VimeoProcessingStatus = 'unprocessed';
                $this->write();
                return false;
            }
        } catch (\Vimeo\Exceptions\VimeoRequestException $e) {
            $this->VimeoProcessingStatus = 'error';
            $this->write();
            $this->appendLog($LogFile, "VimeoRequestException:\n".$e->getMessage());
            return false;
        } catch (\Vimeo\Exceptions\VimeoUploadException $e) {
            $this->VimeoProcessingStatus = 'error';
            $this->write();
            $this->appendLog($LogFile,  "VimeoUploadException:\n".$e->getMessage());
            return false;
        }
    }
    
    protected function extractUrls($data)
    {
        // if status is "available", we need to check if allready all resolution files are really available
        if (isset($data['body']) && isset($data['body']['status']) && $data['body']['status'] == 'available') {
            // fetch source resolution
            $sourceMeasures = array();
            $biggestWidth = 0;
            $biggestHeight = 0;
            foreach ($data['body']['download'] as $dl) {
                if ($biggestWidth < $dl['width']) {
                    $biggestWidth = $dl['width'];
                }
                if ($biggestHeight < $dl['height']) {
                    $biggestHeight = $dl['height'];
                }
                
                // check if source is still existent
                if (isset($dl['type']) && $dl['type'] == 'source') {
                    $sourceMeasures['width'] = $dl['width'];
                    $sourceMeasures['height'] = $dl['height'];
                }
            }
            if (!(isset($sourceMeasures['width']) && isset($sourceMeasures['height']))) {
                $sourceMeasures['width'] = $biggestWidth;
                $sourceMeasures['height'] = $biggestHeight;
            }
            
            // fetch available resolution
            $availRes = array();
            foreach ($data['body']['files'] as $f) {
                if (isset($f['quality']) && isset($f['width']) && isset($f['height']) && isset($f['link']) && isset($f['link_secure'])) {
                    if ($f['quality'] == 'sd') {
                        $availRes['sd'] = $f['link'];
                        $availRes['sd_secure'] = $f['link_secure'];
                    } elseif ($f['quality'] == 'hd' && $f['height'] == 720) {
                        $availRes['hd'] = $f['link'];
                        $availRes['hd_secure'] = $f['link_secure'];
                    } elseif ($f['quality'] == 'hd' && $f['height'] == 1080) {
                        $availRes['fullhd'] = $f['link'];
                        $availRes['fullhd_secure'] = $f['link_secure'];
                    }
                } elseif (isset($f['quality']) && $f['quality'] == 'hls' && isset($f['link']) && isset($f['link_secure'])) {
                    $availRes['hls'] = str_replace('https', 'http', $f['link']);
                    $availRes['hls_secure'] = $f['link_secure'];
                }
            }
            
            if (isset($sourceMeasures['width']) && isset($sourceMeasures['height'])) {
                // source file and measurements found
                // check for highest resolution
                if ($sourceMeasures['width'] >= 1920 && $sourceMeasures['height'] >= 1080) {
                    // Video is full HD, so Full HD should be availalbe
                    if (isset($availRes['fullhd']) && isset($availRes['hd']) && isset($availRes['sd'])) {
                        $this->VimeoFullHDUrl = $availRes['fullhd'];
                        $this->VimeoFullHDUrlSecure = $availRes['fullhd_secure'];
                        $this->VimeoHDUrl = $availRes['hd'];
                        $this->VimeoHDUrlSecure = $availRes['hd_secure'];
                        $this->VimeoSDUrl = $availRes['sd'];
                        $this->VimeoSDUrlSecure = $availRes['sd_secure'];
                        $this->VimeoPicturesURI = $data['body']['pictures']['uri'];
                        if (isset($availRes['hls'])) {
                            $this->VimeoHLSUrl = $availRes['hls'];
                            $this->VimeoHLSUrlSecure = $availRes['hls_secure'];
                        }
                        $this->VimeoProcessingStatus = 'finished';
                        $this->write();
                        return true;
                    } else {
                        return false;
                    }
                } elseif ($sourceMeasures['width'] >= 1280 && $sourceMeasures['height'] >= 720) {
                    // Video is HD, so at least HD schould be available
                    if (isset($availRes['hd']) && isset($availRes['sd'])) {
                        $this->VimeoFullHDUrl = null;
                        $this->VimeoFullHDUrlSecure = null;
                        $this->VimeoHDUrl = $availRes['hd'];
                        $this->VimeoHDUrlSecure = $availRes['hd_secure'];
                        $this->VimeoSDUrl = $availRes['sd'];
                        $this->VimeoSDUrlSecure = $availRes['sd_secure'];
                        $this->VimeoPicturesURI = $data['body']['pictures']['uri'];
                        if (isset($availRes['hls'])) {
                            $this->VimeoHLSUrl = $availRes['hls'];
                            $this->VimeoHLSUrlSecure = $availRes['hls_secure'];
                        }
                        $this->VimeoProcessingStatus = 'finished';
                        $this->write();
                        return true;
                    } else {
                        return false;
                    }
                } else {
                    // Video is SD, so at least SD schould be available
                    if (isset($availRes['sd'])) {
                        $this->VimeoFullHDUrl = null;
                        $this->VimeoFullHDUrlSecure = null;
                        $this->VimeoHDUrl = null;
                        $this->VimeoHDUrlSecure = null;
                        $this->VimeoSDUrl = $availRes['sd'];
                        $this->VimeoSDUrlSecure = $availRes['sd_secure'];
                        $this->VimeoPicturesURI = $data['body']['pictures']['uri'];
                        if (isset($availRes['hls'])) {
                            $this->VimeoHLSUrl = $availRes['hls'];
                            $this->VimeoHLSUrlSecure = $availRes['hls_secure'];
                        }
                        $this->VimeoProcessingStatus = 'finished';
                        $this->write();
                        return true;
                    } else {
                        return false;
                    }
                }
            } else {
                return false;
            }
        } elseif (isset($data['body']) && isset($data['body']['status']) && ($data['body']['status'] == 'quota_exceeded' || $data['body']['status'] == 'uploading_error' || $data['body']['status'] == 'transcoding_error')) {
            $this->VimeoProcessingStatus = 'processingerror';
            $this->write();
            return false;
        } else {
            return false;
        }
    }
    
    public function IsProcessed()
    {
        if ($this->VimeoProcessingStatus == 'finished') {
            return true;
        } else {
            $cache = SS_Cache::factory('VimeoVideoFile_ApiRequest');
            SS_Cache::set_cache_lifetime('VimeoVideoFile_ApiRequest', Config::inst()->get('VimeoVideoFile', 'api_request_time')); // set the waiting time
            if (!($result = $cache->load($this->ID))) {
                switch ($this->VimeoProcessingStatus) {
                    
                    case 'unprocessed':
                        $this->process();
                    break;
                
                    case 'processing':
                        
                        $this->appendLog($this->getLogFile(), 'IsProcessed - processing');
                        
                        $lib = new \Vimeo\Vimeo(Config::inst()->get('VimeoVideoFile', 'client_id'), Config::inst()->get('VimeoVideoFile', 'client_secret'), Config::inst()->get('VimeoVideoFile', 'access_token'));
                        // Send a request to vimeo for uploading the new video
                        $video_data = $lib->request($this->VimeoURI);
                        
                        $this->extractUrls($video_data);
                        
                        // Set Title, Album and player preset
                        $lib->request($this->VimeoURI, array('name' => $this->Name), 'PATCH');
                        if (Config::inst()->get('VimeoVideoFile', 'album_id')) {
                            $res = $lib->request('/me/albums/'.Config::inst()->get('VimeoVideoFile', 'album_id').$this->VimeoURI, array(), 'PUT');
                            $this->appendLog($this->getLogFile(), 'Updated Album', print_r($res, true));
                        }
                        if (Config::inst()->get('VimeoVideoFile', 'preset_id')) {
                            $res = $lib->request($this->VimeoURI.'/presets/'.Config::inst()->get('VimeoVideoFile', 'preset_id'), array(), 'PUT');
                            $this->appendLog($this->getLogFile(), 'Updated Player Preset', print_r($res, true));
                        }
                    break;
                }
                
                $result = $this->VimeoProcessingStatus;
                $cache->save($result, $this->ID);
            }
            return ($result == 'finished');
        }
    }
    
    public function VimeoURI()
    {
        if (!($this->VimeoProcessingStatus == 'error' || $this->VimeoProcessingStatus == 'unprocessed')) {
            return $this->VimeoURI;
        } else {
            return false;
        }
    }
    
    public function VimeoLink()
    {
        if (!($this->VimeoProcessingStatus == 'error' || $this->VimeoProcessingStatus == 'unprocessed')) {
            return $this->VimeoLink;
        } else {
            return false;
        }
    }
    
    public function VimeoID()
    {
        if (!($this->VimeoProcessingStatus == 'error' || $this->VimeoProcessingStatus == 'unprocessed')) {
            return $this->VimeoID;
        } else {
            return false;
        }
    }
    
    public function getFullHDUrl()
    {
        if ($this->VimeoProcessingStatus == 'finished') {
            if ($this->VimeoFullHDUrl) {
                return $this->VimeoFullHDUrl;
            } else {
                return $this->getHDUrl();
            }
        } else {
            return false;
        }
    }
    
    public function getHDUrl()
    {
        if ($this->VimeoProcessingStatus == 'finished') {
            if ($this->VimeoHDUrl) {
                return $this->VimeoHDUrl;
            } else {
                return $this->getSDUrl();
            }
        } else {
            return false;
        }
    }
    
    public function getSDUrl()
    {
        if ($this->VimeoProcessingStatus == 'finished') {
            if ($this->VimeoSDUrl) {
                return $this->VimeoSDUrl;
            } else {
                return false;
            }
        } else {
            return false;
        }
    }
    
    public function getHLSUrl()
    {
        if ($this->VimeoProcessingStatus == 'finished') {
            if ($this->VimeoHLSUrl) {
                return $this->VimeoHLSUrl;
            }
        }
            
        return false;
    }
    
    public function getFullHDUrlSecure()
    {
        if ($this->VimeoProcessingStatus == 'finished') {
            if ($this->VimeoFullHDUrlSecure) {
                return $this->VimeoFullHDUrlSecure;
            } else {
                return $this->getHDUrlSecure();
            }
        } else {
            return false;
        }
    }
    
    public function getHDUrlSecure()
    {
        if ($this->VimeoProcessingStatus == 'finished') {
            if ($this->VimeoHDUrlSecure) {
                return $this->VimeoHDUrlSecure;
            } else {
                return $this->getSDUrlSecure();
            }
        } else {
            return false;
        }
    }
    
    public function getSDUrlSecure()
    {
        if ($this->VimeoProcessingStatus == 'finished') {
            if ($this->VimeoSDUrlSecure) {
                return $this->VimeoSDUrlSecure;
            } else {
                return false;
            }
        } else {
            return false;
        }
    }
    
    public function getHLSUrlSecure()
    {
        if ($this->VimeoProcessingStatus == 'finished') {
            if ($this->VimeoHLSUrlSecure) {
                return $this->VimeoHLSUrlSecure;
            }
        }
            
        return false;
    }
    
    
    public function setPreviewImage(SecureImage $Img)
    {
        if (!($this->PreviewImage() instanceof VideoImage)) {
            if ($this->PreviewImageID > 0 && $this->PreviewImage()) {
                $this->PreviewImage()->delete();
            }
        }
        $this->PreviewImageID = $Img->ID;
        
        $lib = new \Vimeo\Vimeo(Config::inst()->get('VimeoVideoFile', 'client_id'), Config::inst()->get('VimeoVideoFile', 'client_secret'), Config::inst()->get('VimeoVideoFile', 'access_token'));
        
        $video_data = $lib->uploadImage($this->VimeoURI.'/pictures', $Img->getFullPath(), true); // Upload the PreviewPicture as 

        $this->appendLog($this->getLogFile(), 'New Image uploaded', print_r($video_data, true));
        
        $this->write();
    }
     
    
    protected function onBeforeDelete()
    {
        parent::onBeforeDelete();
        
        $lib = new \Vimeo\Vimeo(Config::inst()->get('VimeoVideoFile', 'client_id'), Config::inst()->get('VimeoVideoFile', 'client_secret'), Config::inst()->get('VimeoVideoFile', 'access_token'));
        
        $video_data = $lib->request($this->VimeoURI, array(), 'DELETE');
    }
    
    protected function onAfterProcess()
    {
        parent::onAfterProcess();
    }
    
    public function updateVimeoData()
    {
        if ($this->VimeoProcessingStatus == 'finished') {
            $this->VimeoProcessingStatus = 'updating';
            $this->write();
            
            $this->appendLog($this->getLogFile(), 'IsProcessed - updating');
            
            $lib = new \Vimeo\Vimeo(Config::inst()->get('VimeoVideoFile', 'client_id'), Config::inst()->get('VimeoVideoFile', 'client_secret'), Config::inst()->get('VimeoVideoFile', 'access_token'));
            // Send a request to vimeo for uploading the new video
            $video_data = $lib->request($this->VimeoURI);
            
            $this->extractUrls($video_data);
            
            // Set Title, Album and player preset
            $lib->request($this->VimeoURI, array('name' => $this->Name), 'PATCH');
            if (Config::inst()->get('VimeoVideoFile', 'album_id')) {
                $res = $lib->request('/me/albums/'.Config::inst()->get('VimeoVideoFile', 'album_id').$this->VimeoURI, array(), 'PUT');
                $this->appendLog($this->getLogFile(), 'Updated Album', print_r($res, true));
            }
            if (Config::inst()->get('VimeoVideoFile', 'preset_id')) {
                $res = $lib->request($this->VimeoURI.'/presets/'.Config::inst()->get('VimeoVideoFile', 'preset_id'), array(), 'PUT');
                $this->appendLog($this->getLogFile(), 'Updated Player Preset', print_r($res, true));
            }
            
            return true;
        } else {
            return false;
        }
    }
}
