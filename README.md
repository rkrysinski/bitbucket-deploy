# PHP script to deploy repository from BitBucket to web-server basing on POST hook. #

## Overview ##
* Yet another PHP script to synchronize your repository with webhosting server via BitBucket API. 
* The basic idea is to copy project files to your webhosting server, update conf file and configure BitBucket POST hook, so once you push something to your repository it will be automatically deployed to your server. This is an alternative to syncing files via FTP.
* Besides the files, this offers also an approach for managing the project in specific way in branches, which may or may not be used. The script is good to go with default configuration, but there is something more that you might be interested in.

## Implementation ##
The [BitBucket POST hook](https://confluence.atlassian.com/display/BITBUCKET/POST+hook+management) delivers to PHP script so called 'payload' with information about what was pushed to the repository. This information is used to synchronize repository with files on webhosting server via BitBucket REST API. If the script is run for the first time, it will download all files from BitBucket repository and create a .deploy file. If the .deploy file exists the 'payload' data is used to sync changes. The activities are recorded in debug.log file.

## Installation ##
Copy **deploy.php** and **deploy.conf.php** to your webhosting server.

## Configuration in deploy.conf.php ##
* Set up BitBucket credentials to user that has readonly access to the repository you want to sync.

		...
		"user" => 'bitbucket-user-name',
		"pass" => 'bitbucket-user-password',
		...

* The PHP script can be configured to deploy specific branches to specific directories. Let's assume you have production code in '*master*' branch, and current changes in '*dev*' branch. You can configure to have '*dev*' branch to be synced with  */<www_root>/tes*t directory on server, so you do not touch production files, and you have a way to test it before making then official. Once you have done your changes in '*dev*' branch, you can merge it back to the '*master*' and then the script will deploy changes to */<www_root>/* directory making then official. Here is the sample configuration file to achieve this: 

		...
		"branch_to_dir" => [
				"dev" => "test", 
				"master" => "",
		],
		...

	**NOTE**: When no branch-dir mapping defined, the script will create a directory with the same name as branch e.g. when committing something to branch '*demo*' and no mapping defined in "branch_to_dir", the script will deploy changes to  */<www_root>/demo* directory on server.

* You can configure the script to download only some part of repository to server. It can be useful in cases where you have project with the following layout:

		.
		|-app
		|---bower_components
		|-----angular
		|-----html5-boilerplate
		|-------css
		|-------doc
		|-------img
		|-------js
		|---------vendor
		|---css
		|---img
		|---js
		|---partials
		|-test
		|---e2e
		|---unit
		bower.json
		Gruntfile.js
		LICENSE
		package.json	


	and you want to deploy only the 'app' directory - the actual code that belongs to the server. Here is the sample configuration file to achieve this:

		...
		"repository_root" => "app",
		...

* It may happen that your application is combined with some building blocks that are stored in separate repositories. You can configure script to handle payloads coming from different repositories' Hooks. In order to do that you have to set the "repository_mapping" configuration. Let's assume you have AngularJS front-end with PHP REST back-end, where PHP code is stored in different BitBucket repository e.g. "php-rest-api". In order to configure payload for for PHP code you have to configure the following:

		...
		"repository_mapping" => [
			"php-rest-api" => [
				"branch_to_dir" => [
					"dev" => "test",
				],
				"repository_root" => "",
				]
		],
		...

	**NOTE**: The "branch_to_dir" and "repository_root" have the same effect like described previously, so in this example the '*dev*' branch will be deployed to */<www_root>/test* directory on server and all repository files are copied. Additional POST Hook in "php-rest-api" repository should be defined.

* You can turn on the debug mode to see more details in log file. 

		...
		"debug" => false,
		...

## POST hook configuration ##
Configure your repository to send POST to deploy.php on commit. 

* Go to the repository's  settings.
* Click **Hooks** in the left-hand navigation. The Hooks page appears.
* Select the **POST** hook from the **Hook** dropdown.
* Click **Add hook**. A new section appears for the **POST** hook.
* Enter the URL where Bitbucket should send its update messages: e.g. https://<webhosting_URL>/deploy.php
* Press **Save**.

## License ##
[Apache License, Version 2.0](http://www.apache.org/licenses/LICENSE-2.0)