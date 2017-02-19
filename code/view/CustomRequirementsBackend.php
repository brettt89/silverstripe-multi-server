<?php

/**
 * CustomRequirementsBackend overwrites specific javascript functionality to use MD5 instead of filemtime
 */
class CustomRequirementsBackend extends Requirements_Backend {

	/**
	 * Register the given JavaScript file as required.
	 *
	 * @param string $file Relative to docroot
	 */
	public function javascript($file) {
		// if there's no file, then it must be a dynamic javascript request.
		// we need to calculate the hash directly off the query components
		if(false === file_exists(Director::getAbsFile($file))){
			$this->javascript[$file] = md5($file);
			return;
		}

		// store a md5 hash of the file to work out if it's actually changed
		$this->javascript[$file] = hash_file('md5', Director::getAbsFile($file));
	}

	/**
	 * Finds the path for specified file
	 *
	 * @param string $fileOrUrl
	 * @return string|bool
	 */
	protected function path_for_file($fileOrUrl) {
		if(preg_match('{^//|http[s]?}', $fileOrUrl)) {
			return $fileOrUrl;
		} elseif(Director::fileExists($fileOrUrl)) {
			$filePath = preg_replace('/\?.*/', '', Director::baseFolder() . '/' . $fileOrUrl);
			$prefix = Director::baseURL();
			$mtimesuffix = "";
			$suffix = '';
			if($this->suffix_requirements) {
				$mtimesuffix = "?md5=" . hash_file('md5', $filePath);
				$suffix = '&';
			}
			if(strpos($fileOrUrl, '?') !== false) {
				if (strlen($suffix) == 0) {
					$suffix = '?';
				}
				$suffix .= substr($fileOrUrl, strpos($fileOrUrl, '?')+1);
				$fileOrUrl = substr($fileOrUrl, 0, strpos($fileOrUrl, '?'));
			} else {
				$suffix = '';
			}
			return "{$prefix}{$fileOrUrl}{$mtimesuffix}{$suffix}";
		} else {
			return false;
		}
	}

	/**
	 * Do the heavy lifting involved in combining (and, in the case of JavaScript minifying) the
	 * combined files.
	 */
	public function process_combined_files() {
		// The class_exists call prevents us loading SapphireTest.php (slow) just to know that
		// SapphireTest isn't running :-)
		if(class_exists('SapphireTest', false)) $runningTest = SapphireTest::is_running_test();
		else $runningTest = false;

		if((Director::isDev() && !$runningTest && !isset($_REQUEST['combine'])) || !$this->combined_files_enabled) {
			return;
		}

		// Make a map of files that could be potentially combined
		$combinerCheck = array();
		foreach($this->combine_files as $combinedFile => $sourceItems) {
			foreach($sourceItems as $sourceItem) {
				if(isset($combinerCheck[$sourceItem]) && $combinerCheck[$sourceItem] != $combinedFile){
					user_error("Requirements_Backend::process_combined_files - file '$sourceItem' appears in two " .
						"combined files:" .	" '{$combinerCheck[$sourceItem]}' and '$combinedFile'", E_USER_WARNING);
				}
				$combinerCheck[$sourceItem] = $combinedFile;

			}
		}

		// Work out the relative URL for the combined files from the base folder
		$combinedFilesFolder = ($this->getCombinedFilesFolder()) ? ($this->getCombinedFilesFolder() . '/') : '';

		// Figure out which ones apply to this request
		$combinedFiles = array();
		$newJSRequirements = array();
		$newCSSRequirements = array();
		foreach($this->javascript as $file => $hash) {
			if(isset($combinerCheck[$file])) {
				$newJSRequirements[$combinedFilesFolder . $combinerCheck[$file]] = true;
				$combinedFiles[$combinerCheck[$file]] = $hash;
			} else {
				$newJSRequirements[$file] = true;
			}
		}

		foreach($this->css as $file => $params) {
			if(isset($combinerCheck[$file])) {
				// Inherit the parameters from the last file in the combine set.
				$newCSSRequirements[$combinedFilesFolder . $combinerCheck[$file]] = $params;
				$combinedFiles[$combinerCheck[$file]] = true;
			} else {
				$newCSSRequirements[$file] = $params;
			}
		}

		// Process the combined files
		$base = Director::baseFolder() . '/';
		foreach(array_diff_key($combinedFiles, $this->blocked) as $combinedFile => $dummy) {
			$fileList = $this->combine_files[$combinedFile];
			$combinedFilePath = $base . $combinedFilesFolder . '/' . $combinedFile;


			// Make the folder if necessary
			if(!file_exists(dirname($combinedFilePath))) {
				Filesystem::makeFolder(dirname($combinedFilePath));
			}

			// If the file isn't writeable, don't even bother trying to make the combined file and return. The
			// files will be included individually instead. This is a complex test because is_writable fails
			// if the file doesn't exist yet.
			if((file_exists($combinedFilePath) && !is_writable($combinedFilePath))
				|| (!file_exists($combinedFilePath) && !is_writable(dirname($combinedFilePath)))
			) {
				user_error("Requirements_Backend::process_combined_files(): Couldn't create '$combinedFilePath'",
					E_USER_WARNING);
				return false;
			}

			// Determine if we need to build the combined include
			if(file_exists($combinedFilePath)) {
				// file exists, check modification date of every contained file
				$refresh = false;
				foreach($fileList as $file) {
					$fileName = $base . $file;
					if(file_exists($fileName)) {
						// only calculate the hash until we find one that isn't.
						if($refresh === false && $this->javascript[ltrim(Director::makeRelative($fileName),'/')] !== hash_file('md5', $fileName)){
							$refresh = true;
						}
					}
				}
			} else {
				// File doesn't exist, or refresh was explicitly required
				$refresh = true;
			}

			if(!$refresh) continue;

			$failedToMinify = false;
			$combinedData = "";
			foreach(array_diff($fileList, $this->blocked) as $file) {
				$fileContent = file_get_contents($base . $file);

				try{
					$fileContent = $this->minifyFile($file, $fileContent);
				}catch(Exception $e){
					$failedToMinify = true;
				}

				if ($this->write_header_comment) {
					// Write a header comment for each file for easier identification and debugging. The semicolon between each file is required for jQuery to be combined properly and protects against unterminated statements.
					$combinedData .= "/****** FILE: $file *****/\n";
				}

				$combinedData .= $fileContent . "\n";
			}

			$successfulWrite = false;
			$fh = fopen($combinedFilePath, 'wb');
			if($fh) {
				if(fwrite($fh, $combinedData) == strlen($combinedData)) $successfulWrite = true;
				fclose($fh);
				unset($fh);
			}

			if($failedToMinify){
				// Failed to minify, use unminified files instead. This warning is raised at the end to allow code execution
				// to complete in case this warning is caught inside a try-catch block.
				user_error('Failed to minify '.$file.', exception: '.$e->getMessage(), E_USER_WARNING);
			}

			// Unsuccessful write - just include the regular JS files, rather than the combined one
			if(!$successfulWrite) {
				user_error("Requirements_Backend::process_combined_files(): Couldn't create '$combinedFilePath'",
					E_USER_WARNING);
				continue;
			}
		}

		// Note: Alters the original information, which means you can't call this method repeatedly - it will behave
		// differently on the subsequent calls
		$this->javascript = $newJSRequirements;
		$this->css = $newCSSRequirements;
	}
}