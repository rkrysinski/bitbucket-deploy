<?php
/*
 * (C) Copyright 2014 QDEVE.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 */
 
require_once('deploy.conf.php');

class DeployManager 
{
	private $logDescriptor;
	private $config;
	private $payload;
	private $isDebug;
	private $dst_dir;
	private $branch_to_dir;
	private $repository_root;
	private $deploy_marker;
	
	const LOG_FILENAME = "debug.log";
	const BITBUCKET_URL = "https://api.bitbucket.org/1.0/repositories";
	
	public function __construct($config) 
	{
		$this->config = $config;
		$this->isDebug = $config["debug"];
		$this->logDescriptor = fopen(self::LOG_FILENAME, "a+");
		self::log("************ Deploy in progress. ************");
	}
	
	public function __destruct() 
	{ 
		self::log("************ Deploy finished. ************");
		fclose($this->logDescriptor);
	}
	
	public function consume_payload($payload) 
	{
		self::debug("consume_payload: begin");
		
		if (!isset($payload)) 
		{
			self::log("Nothing to consume, aborting...");
			return;
		}
	
		$json = stripslashes($payload);
		$this->payload = json_decode($json);

		self::debug("consume_payload: payload:" . json_encode($this->payload, JSON_PRETTY_PRINT));
		
		self::initialize_deploy_config();	
		
		foreach($this->payload->commits as $commit) 
		{
			self::deploy($commit);	
		}
		
		self::debug("consume_payload: end");
	}
	
	private function deploy($commit)
	{
		self::initialize_deploy_destination_dir($commit->branch);

		if (self::is_initial_deploy()) 
		{
			self::deploy_from_scratch($commit);
		} 
		else 
		{
			self::deploy_commit($commit);
		}
	}
	
	private function deploy_from_scratch($commit)
	{
		self::log("deploy_from_scratch to " . $this->dst_dir . " directory");
		
		self::mkdir($this->dst_dir);
		chdir($this->dst_dir);
		self::debug("current dir: " . getcwd());
		
		self::download_node($commit->node);
		
		touch($this->deploy_marker);

		self::log("deploy_from_scratch finished");
	}

	private function download_node($node, $dir="")
	{
		$node_data = self::get_bitbucket_src($node, $dir);
		self::debug("node_data: " . json_encode($node_data, JSON_PRETTY_PRINT));
		foreach ($node_data->files as $file) 
		{
			self::do_download_file($node, $file->path);
		}
		foreach ($node_data->directories as $directory) 
		{
			self::download_node($node, $dir . $directory . "/");
		}
	}
		
	private function get_bitbucket_src($revision, $dir)
	{
		self::debug("get_bitbucket_src begin");
		$url = self::BITBUCKET_URL . $this->payload->repository->absolute_url 
				. "src/" . $revision . "/" . $this->repository_root . "/" . $dir;
		self::debug("get_bitbucket_src $url");
		$data = self::do_get_src($url);
		return json_decode($data);		
	}

	private function do_download_file($revision, $file)
	{
		self::debug("do_download_file begin");
		$file_on_disk = self::get_file_location_on_disk($file);
		$url = self::BITBUCKET_URL . $this->payload->repository->absolute_url 
				. "raw/" . $revision . "/" . $file;
		self::debug("do_download_file ". $url);
		$ch = self::do_curl_init($url);
		$fp = fopen($file_on_disk, 'w');
		curl_setopt($ch, CURLOPT_FILE, $fp);
		curl_exec($ch);
		curl_close($ch);
		fclose($fp);
		self::log("Download (repo) $file -> $file_on_disk (disk)");
		self::debug("do_download_file end");
	}
	
	private function do_get_src($url)
	{
		self::debug("do_get_src begin");
		$ch = self::do_curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);		
		$data = curl_exec($ch);
		curl_close($ch);
		return $data;
	}
	
	private function deploy_commit($commit)
	{
		self::log("deploy_commit to " . $this->dst_dir . " directory");	
		self::debug("commit: " . json_encode($commit, JSON_PRETTY_PRINT));
		
		foreach ($commit->files as $file) 
		{
			if (self::not_starts_with($file->file, $this->repository_root)) 
			{
				// file that should not be deployed due to configuration
				continue;
			}
			
			self::log("deploy_commit [" . $file->type . "] " . $file->file);
			
			if ($file->type == "removed")
			{
				self::do_remove_file($file->file);
			} 
			else
			{
				self::do_download_file($commit->node, $file->file);				
			}			
		}
		
		self::log("deploy_commit finished");
	}
	
	private function do_remove_file($file)
	{
		$file_on_disk = self::get_file_location_on_disk($file);
		self::log("Removing $file_on_disk");
		unlink($file_on_disk);
	}
	
	private function get_file_location_on_disk($file_in_repo)
	{
		$path_parts = pathinfo($file_in_repo);
		$file_path = $path_parts['dirname'] . "/";
		$file_name = $path_parts['basename'];
		$file_dir_on_disk = $this->dst_dir . "/" . substr($file_path, strlen($this->repository_root), strlen($file_path));
		self::mkdir($file_dir_on_disk);
		return $file_dir_on_disk . "/" . $file_name;
	}
	
	private function not_starts_with($haystack, $needle)
	{
		 $length = strlen($needle);
		 return !(substr($haystack, 0, $length) === $needle);
	}
	
	private function do_curl_init($url)
	{
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_USERPWD, $this->config["user"] . ":" . $this->config["pass"]);
		return $ch;
	}
	
	private function is_initial_deploy()
	{
		return !file_exists($this->dst_dir . "/" . $this->deploy_marker);
	}
	
	private function initialize_deploy_destination_dir($branch) 
	{
		self::debug("initialize_deploy_destination_dir: start");
		
		$branch_to_dir = $this->branch_to_dir;
		$root_dir = $branch;
		if (array_key_exists($branch, $branch_to_dir)) 
		{
			$root_dir = $branch_to_dir[$branch];
		}

		$this->dst_dir = getcwd() . "/" . $root_dir;
		
		self::debug("initialize_deploy_destination_dir: end= " . $this->dst_dir);
	}

	private function initialize_deploy_config()
	{
		$repo = $this->payload->repository->name;
		$repository_mapping = $this->config["repository_mapping"];
		if (array_key_exists($repo, $repository_mapping))
		{
			$this->branch_to_dir = $repository_mapping[$repo]["branch_to_dir"];
			$this->repository_root = $repository_mapping[$repo]["repository_root"];
		}
		else
		{
			$this->branch_to_dir = $this->config["branch_to_dir"];
			$this->repository_root = $this->config["repository_root"];	
		}
		
		$this->deploy_marker = ".deploy_" . $repo;
		
		self::debug("initialize_deploy_config: \nbranch_to_dir = " 
			. var_export($this->branch_to_dir, true) 
			. "\nrepository_root: $this->repository_root"
			. "\ndeploy_marker: $this->deploy_marker"
		);
	}
	
	private function mkdir($dir_name)
	{
		if (file_exists($dir_name)) 
		{
			self::debug("mkdir: $dir_name already exists, skipping...");		
		} 
		else 
		{
			$result = mkdir($dir_name, 0777, TRUE);
			self::debug("mkdir: $dir_name ($result)");
		}
	}
	
	private function log($text) 
	{
		$msg = date("d.m.Y, H:i:s",time()) . ': ' . $text . "\n";
		fputs($this->logDescriptor, $msg);
	}
	
	private function debug($text) 
	{
		if ($this->isDebug) {
			self::log($text);
		}
	}
}

global $configuration;
$deploy_manager = new DeployManager($configuration);
$deploy_manager->consume_payload($_POST['payload']);
$deploy_manager = null;

?>
Empty page
