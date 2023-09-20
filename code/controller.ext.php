<?php
/**
 * Controller script for SenBrand Module for Sentora 2.x.x
 * Version : 1.2.0
 * Author : TGates
 * Info : http://sentora.org
 */

// Normal functions
// Function to retrieve remote XML for update check
function check_remote_xml($xmlurl,$destfile)
{
	if (file_exists($xmlurl))
	{
		$feed = simplexml_load_file($xmlurl);
		if ($feed)
		{
			// $feed is valid, save it
			$feed->asXML($destfile);
		}
		elseif (file_exists($destfile))
		{
			// $feed is not valid, grab the last backup
			$feed = simplexml_load_file($destfile);
		}
		else
		{
			die('Unable to retrieve XML file');
		}
		die('No update data available');
	}
}

// Class controller & static functions
class module_controller
{
	static $error;
    static $ok;
	static $verify;
    static $error_message;
	
	// Module update check functions
    static function getModuleVersion()
	{
        global $controller;

        $module_path="./modules/" . $controller->GetControllerRequest('URL', 'module');
        
        // Get Update URL and Version From module.xml
        $mod_xml = "./modules/" . $controller->GetControllerRequest('URL', 'module') . "/module.xml";
        $mod_config = new xml_reader(fs_filehandler::ReadFileContents($mod_xml));
        $mod_config->Parse();
        $module_version = $mod_config->document->version[0]->tagData;
        return "v".$module_version."";
    }
	
    static function getCheckUpdate()
	{
        global $zdbh, $controller, $zlo;
        $module_path="./modules/" . $controller->GetControllerRequest('URL', 'module');
        
        // Get Update URL and Version From module.xml
        $mod_xml = "./modules/" . $controller->GetControllerRequest('URL', 'module') . "/module.xml";
        $mod_config = new xml_reader(fs_filehandler::ReadFileContents($mod_xml));
        $mod_config->Parse();
        $module_updateurl = $mod_config->document->updateurl[0]->tagData;
        $module_version = $mod_config->document->version[0]->tagData;

        // Download XML in Update URL and get Download URL and Version
        $myfile = check_remote_xml($module_updateurl, $module_path."/" . $controller->GetControllerRequest('URL', 'module') . ".xml");
		if (!file_exists($myfile))
		{
			return false;
		}
		else
		{
			$update_config = new xml_reader(fs_filehandler::ReadFileContents($module_path."/" . $controller->GetControllerRequest('URL', 'module') . ".xml"));
			$update_config->Parse();
			$update_url = $update_config->document->downloadurl[0]->tagData;
			$update_version = $update_config->document->latestversion[0]->tagData;

			if($update_version > $module_version) return true;
			return false;
		}
    }

    static function getBrandingImage()
	{
        global $zdbh, $controller;
		// get branding logo URL
		$stmt = $zdbh->prepare("SELECT sb_logo_tx FROM x_senbrand WHERE sb_id_nm = '1'"); 
		$stmt->execute(); 
		$brandSettings = $stmt->fetch();
		
		$branding_image_url = $brandSettings['sb_logo_tx'];
		
        if (!$branding_image_url)
		{
			$brandingImage = ui_language::translate("No branding image.");
		}
		else
		{
			$brandingImage = "<img src=" . $branding_image_url . " alt=\"Branding Image\">";
		}
        return $brandingImage;
    }

	static function getBranding()
	{
		global $zdbh, $controller;
		$toReturn = "";
		// get branding information
		$stmt = $zdbh->prepare("SELECT * FROM x_senbrand WHERE sb_id_nm = '1'"); 
		$stmt->execute(); 
		$brandSettings = $stmt->fetch();
		// create the form
		$toReturn .= "
		<table class=\"table table-striped sortable\" border=\"0\" width=\"100%\">
			<tr>
				<td>
					<form id=\"install\" name=\"branding\" action=\"/?module=senbrand&action=Update\" method=\"post\">";
			$toReturn .= "<p></p>
						<div class=\"form-group\">
							<label for=\"co_name_tx\">" . ui_language::translate("Company Name") . ":
							<input
								type=\"text\"
								class=\"form-control\"
								name=\"inCoName\"
								id=\"co_name_tx\"
								value=\"" . $brandSettings['sb_name_tx'] . "\" required>
							</label>
						</div>
						<div class=\"form-group\">
							<label for=\"co_url_tx\">" . ui_language::translate("Website URL") . ":
							<input
								type=\"text\"
								class=\"form-control\"
								name=\"inCoUrl\"
								id=\"co_url_tx\"
								value=\"" . $brandSettings['sb_url_tx'] . "\" required>
							</label>
						</div>";
			$toReturn .= "<p></p>
						<button class=\"btn btn-primary\" type=\"submit\" name=\"doUpdate\" value=\"Update\">" .  ui_language::translate("Update") . "</button>
					</form>
				</td>
			</tr>
		</table>";

		return $toReturn;
	}

	static function doUpdate()
	{
		global $controller;
		//runtime_csfr::Protect();
		$formvars = $controller->GetAllControllerRequests('FORM');

		if (self::ExecuteUpdate($formvars['inEnabled'], $formvars['inCoName'], $formvars['inCoUrl']))
		return true;
	}

	static function ExecuteUpdate($inEnabled, $inCoName, $inCoUrl)
	{
		global $zdbh, $controller;

		$stmt = $zdbh->prepare("
					UPDATE x_senbrand SET
					sb_name_tx = :inCoName,
					sb_url_tx = :inCoUrl
					WHERE sb_id_nm = '1'
				");

		$stmt->bindParam(':inCoName', $inCoName);
		$stmt->bindParam(':inCoUrl', $inCoUrl);
		$stmt->execute();

		self::$ok = true;
		return true;
	}

    static function doUploadLogo()
    {
		global $zdbh, $controller;
		//runtime_csfr::Protect();
		self::$error_message = "";
        self::$error = false;
        if ($_FILES['brandinglogo']['error'] > 0)
		{
			self::$error_message = ui_language::translate("Couldn't upload the file") . ", " . $_FILES['brandinglogo']['error'];
            echo ui_language::translate("Couldn't upload the file") . ", " . $_FILES['brandinglogo']['error'];
			exit();
        }
		else
		{
			$logo_dir = ctrl_options::GetSystemOption('sentora_root') . 'modules/senbrand/branding_logo/';
			
			// remove old branding image
			$files = glob($logo_dir . '*');
			foreach ($files as $file)
			{
				if (is_file($file))
				unlink($file);
			}
			
			// set some variables
			$logo_name = $_FILES['brandinglogo']['name'];
			$logo_size = $_FILES['brandinglogo']['size'];
			$logo_type = $_FILES['brandinglogo']['type'];
			$logo_tmp_name = $_FILES['brandinglogo']['tmp_name'];

			// convert image to png
			imagepng(imagecreatefromstring(file_get_contents($logo_tmp_name)), $logo_dir . "senbrand_logo.png");

			// set logo URL paths
			$logo_path = $logo_dir . "senbrand_logo.png";

			$logo_url = '/modules/senbrand/branding_logo/senbrand_logo.png';
			
			// do some checks and add to database if is image
			if (isset($logo_name)) 
			{
				if (!empty($logo_name)) 
				{
					if (move_uploaded_file($logo_tmp_name, $logo_path))
					{
						// resize image
						require('modules/senbrand/code/smart_image_resize.php');      
						smart_resize_image($logo_path, null, 100, 60, true, $logo_path, false, false, 100);

						// save image URL
						$stmt = $zdbh->prepare("UPDATE x_senbrand SET sb_logo_tx = :inCoLogo WHERE sb_id_nm = '1'");
						$stmt->bindParam(':inCoLogo', $logo_url);
						$stmt->execute();
						self::$ok = true;
					}
					else
					{
						self::$error_message = ui_language::translate("Image file not uploaded!");
					}
				}
				else 
				{
					self::$error_message = ui_language::translate("Please choose an image file!");
				}		
			}
        }
        return;
    }

    static function ExecuteStylesList()
    {
        return ui_template::ListAvaliableTemplates();
    }

    static function getCurrentTheme()
    {
        global $controller;
        $currentuser = ctrl_users::GetUserDetail();
        return self::ExecuteShowCurrentTheme($currentuser['userid']);
    }

    static function ExecuteShowCurrentTheme($uid)
    {
        return ui_template::GetUserTemplate();
    }

    static function getSelectThemeMenu()
    {
		global $zdbh;
		
		// get edited themes list
		$stmt = $zdbh->prepare("SELECT sb_themes_tx FROM x_senbrand_themes");
		$stmt->execute();
		$themes = $stmt->fetchAll();
		
		$themes = array_column($themes, 'sb_themes_tx');
		
        $html = "";
        foreach (self::ExecuteStylesList() as $theme)
		{
			if (in_array($theme['name'], $themes))
			{
				$status = " - " . ui_language::translate('Branded');
			}
			else
			{
				$status = " - " . ui_language::translate('Default');
			}
			
            if ($theme['name'] != self::getCurrentTheme())
			{
                $html .="<option value = \"" . $theme['name'] . "\">" . $theme['name'] . $status . "</option>\n";
            }
			else
			{
                $html .="<option value = \"" . $theme['name'] . "\" selected=\"selected\">" . $theme['name'] . $status . "</option>\n";
            }
        }
        return $html;
    }

    static function doEditTheme()
    {
        global $zdbh, $controller;
 
        //runtime_csfr::Protect();
		
		self::$error_message = "";
        self::$error = false;
		self::$verify = "";

		// get branding information
		$stmt = $zdbh->prepare("SELECT * FROM x_senbrand WHERE sb_id_nm = '1'"); 
		$stmt->execute(); 
		$brandSettings = $stmt->fetch();
		
        $currentuser = ctrl_users::GetUserDetail();
        $formvars = $controller->GetAllControllerRequests('FORM');

		if ($formvars['inEdit'] && $formvars['inEdit'] == "Edit")
		{
			// add theme name to DB
			$stmt = $zdbh->prepare("
						INSERT IGNORE INTO x_senbrand_themes (sb_themes_tx)
						VALUES (:inTheme) 
					");
			$stmt->bindParam(':inTheme', $formvars['inTheme']);
			$stmt->execute();
			
			// master.ztml hook
			$path_to_master = "/etc/sentora/panel/etc/styles/" . $formvars['inTheme'] . "/master.ztml";
			$find_master = "<# ui_tpl_assetfolderpath #>img/logos/sentora_logo_header.png";
			// check to see if stirng exists in master.ztml
			$search = fopen($path_to_master, 'r');
			$valid = false;
			while (($buffer = fgets($search)) !== false)
			{
				if (strpos($buffer, $find_master) !== false)
				{
					$valid = TRUE;
					break;
				}      
			}
			fclose($search);
			// edit master.ztml if string exists
			if ($valid)
			{
				$replace_master = $brandSettings['sb_logo_tx'];
				$file_contents = file_get_contents($path_to_master);
				$file_contents = str_replace($find_master, $replace_master, $file_contents);
				file_put_contents($path_to_master, $file_contents);
				self::$verify .= ui_language::translate("SenBrand has edited") . " master.ztml<br>";
			}
			else
			{
				self::$error_message .= ui_language::translate("SenBrand can not edit") . " master.ztml - " . $formvars['inTheme'] . "<br>";
			}
			
			// login.ztml hook
			$path_to_login = "/etc/sentora/panel/etc/styles/" . $formvars['inTheme'] . "/login.ztml";
			$find_login = "/etc/styles/" . $formvars['inTheme'] . "/img/logos/sentora_logo.png";
			// check to see if stirng exists in login.ztml
			$search = fopen($path_to_login, 'r');
			$valid = false;
			while (($buffer = fgets($search)) !== false)
			{
				if (strpos($buffer, $find_login) !== false)
				{
					$valid = TRUE;
					break;
				}      
			}
			fclose($search);
			// edit login.ztml if string exists
			if ($valid)
			{
				$replace_login = $brandSettings['sb_logo_tx'];
				$file_contents = file_get_contents($path_to_login);
				
				$file_contents = str_replace($find_login, $replace_login, $file_contents);
				file_put_contents($path_to_login, $file_contents);
				self::$verify .= ui_language::translate("SenBrand has edited") . " login.ztml<br>";
			}
			else
			{
				self::$error_message .= ui_language::translate("SenBrand can not edit") . " login.ztml - " . $formvars['inTheme'] . "<br>";
			}
			// edit login.ztml 'powered by'
			// check to see if stirng exists in login.ztml
			$search = fopen($path_to_login, 'r');
			$valid = false;
			while (($buffer = fgets($search)) !== false)
			{
				if (strpos($buffer, "Powered by") !== false)
				{
					$valid = TRUE;
					break;
				}      
			}
			fclose($search);
			// edit login.ztml if string exists
			if ($valid)
			{
				$replace_login = "Hosted by <a href=\"" . $brandSettings['sb_url_tx'] . "\">" . $brandSettings['sb_name_tx'] . "</a><br>Powered by";
				$file_contents = file_get_contents($path_to_login);
				
				$file_contents = str_replace("Powered by", $replace_login, $file_contents);
				file_put_contents($path_to_login, $file_contents);
				self::$verify .= ui_language::translate("SenBrand has edited") . " login.ztml Powered by " . ui_language::translate("entry") . "<br>";
			}
			else
			{
				self::$error_message .= ui_language::translate("SenBrand can not edit") . " login.ztml Powered by " . ui_language::translate("entry for") . $formvars['inTheme'] . "<br>";
			}
			return;
  		}
		else if ($formvars['inUndo'] && $formvars['inUndo'] == "Undo")
		{
			// check if theme exists in DB
			$check = $zdbh->prepare("
						SELECT sb_themes_tx
						FROM x_senbrand_themes
						WHERE sb_themes_tx = :inTheme
					");
			$check->bindParam(":inTheme", $formvars['inTheme']);
			$check->execute();
			$empty = $check->rowCount();

			// remove theme name from DB if exists
			if ($empty > 0 )
			{
				$stmt = $zdbh->prepare("
							DELETE FROM x_senbrand_themes
							WHERE sb_themes_tx = :inTheme
						");
				$stmt->bindParam(':inTheme', $formvars['inTheme']);
				$stmt->execute();
			}
			
			// undo master.ztml hook
			$path_to_master = "/etc/sentora/panel/etc/styles/" . $formvars['inTheme'] . "/master.ztml";
			$find_master = $brandSettings['sb_logo_tx'];
			
			// check to see if stirng exists in master.ztml
			$search = fopen($path_to_master, 'r');
			$valid = false;
			while (($buffer = fgets($search)) !== false)
			{
				if (strpos($buffer, $find_master) !== false)
				{
					$valid = TRUE;
					break;
				}      
			}
			fclose($search);
			// change master.ztml back to original
			if ($valid)
			{
				$replace_master = "<# ui_tpl_assetfolderpath #>img/logos/sentora_logo_header.png";
				$file_contents = file_get_contents($path_to_master);
				$file_contents = str_replace($find_master, $replace_master, $file_contents);
				file_put_contents($path_to_master, $file_contents);
				self::$verify .= ui_language::translate("SenBrand has reverted the changes to") . " master.ztml - " . $formvars['inTheme'] . "<br>";
			}
			else
			{
				self::$error_message = ui_language::translate("SenBrand can not edit") . " master.ztml - " . $formvars['inTheme'] . "<br>";
			}
			
			// undo login.ztml hook
			$path_to_login = "/etc/sentora/panel/etc/styles/" . $formvars['inTheme'] . "/login.ztml";
			$find_login = $brandSettings['sb_logo_tx'];
			// check to see if stirng exists in master.ztml
			$search = fopen($path_to_login, 'r');
			$valid = false;
			while (($buffer = fgets($search)) !== false)
			{
				if (strpos($buffer, $find_login) !== false)
				{
					$valid = TRUE;
					break;
				}      
			}
			fclose($search);
			// change login.ztml back to original
			if ($valid)
			{
				$replace_login = "/etc/styles/" . $formvars['inTheme'] . "/img/logos/sentora_logo.png";
				$file_contents = file_get_contents($path_to_login);
				
				$file_contents = str_replace($find_login, $replace_login, $file_contents);
				file_put_contents($path_to_login, $file_contents);
				self::$verify .= ui_language::translate("SenBrand has reverted the changes to") . " login.ztml - " . $formvars['inTheme'] . "<br>";
			}
			else
			{
				self::$error_message = ui_language::translate("SenBrand can not edit") . " login.ztml - " . $formvars['inTheme'] . "<br>";
			}
			// undo login.ztml 'powered by'
			// check to see if stirng exists in login.ztml
			$powered_by = "Hosted by <a href=\"" . $brandSettings['sb_url_tx'] . "\">" . $brandSettings['sb_name_tx'] . "</a><br>Powered by";
			$search = fopen($path_to_login, 'r');
			$valid = false;
			while (($buffer = fgets($search)) !== false)
			{
				if (strpos($buffer, $powered_by) !== false)
				{
					$valid = TRUE;
					break;
				}      
			}
			fclose($search);
			// undo login.ztml if string exists
			if ($valid)
			{
				$replace_login = "Hosted by <a href=\"" . $brandSettings['sb_url_tx'] . "\">" . $brandSettings['sb_name_tx'] . "</a><br>Powered by";
				$file_contents = file_get_contents($path_to_login);
				
				$file_contents = str_replace($replace_login, "Powered by", $file_contents);
				file_put_contents($path_to_login, $file_contents);
				self::$verify .= ui_language::translate("SenBrand has edited") . " login.ztml Powered by " . ui_language::translate("entry") . "<br>";
			}
			else
			{
				self::$error_message .= ui_language::translate("SenBrand can not edit") . " login.ztml Powered by " . ui_language::translate("entry for") . $formvars['inTheme'] . "<br>";
			}
			return;
		}
		else
		{
			echo ui_language::translate("You are not accessing this file properly!");
			exit();
		}
    }


    static function getNotice()
    {
        return ui_sysmessage::shout(ui_language::translate("This module only works on the default theme and themes built directly from the default theme.", 'zannounceerror', 'Notice'));
    }
	
    static function getModuleDesc()
	{
        $message = ui_language::translate("This module allows the server owner to custom brand Sentora to their hosting company.");
        return $message;
    }

	static function getModuleName()
	{
		$module_name = ui_module::GetModuleName();
        return $module_name;
    }

	static function getModuleIcon()
	{
		global $controller;
		$module_icon = "/modules/" . $controller->GetControllerRequest('URL', 'module') . "/assets/icon.png";
        return $module_icon;
    }

    static function getCSFR_Tag()
	{
        return runtime_csfr::Token();
    }
	
    static function getResult()
    {
        if (!fs_director::CheckForEmptyValue(self::$error_message))
            return ui_sysmessage::shout(ui_language::translate(self::$error_message), 'zannounceerror', 'Notice:');
        if (!fs_director::CheckForEmptyValue(self::$ok))
			return ui_sysmessage::shout(ui_language::translate("Changes Updated!"), 'zannouncesuccess', 'Success!');
        if (!fs_director::CheckForEmptyValue(self::$verify))
			return ui_sysmessage::shout(ui_language::translate(self::$verify), 'zannouncesuccess', 'Success!');
        return;
    }
	
    static function getCopyright()
	{
        $message = '<font face="ariel" size="2">'.ui_module::GetModuleName().' v1.2.0 &copy; 2017-'.date("Y").' by <a target="_blank" href="http://forums.sentora.org/member.php?action=profile&uid=2">TGates</a> for <a target="_blank" href="http://sentora.org">Sentora Control Panel</a>&nbsp;&#8212;&nbsp;Help support future development of this module and donate today!</font>
<form action="https://www.paypal.com/cgi-bin/webscr" method="post" target="_blank">
<input type="hidden" name="cmd" value="_s-xclick">
<input type="hidden" name="hosted_button_id" value="DW8QTHWW4FMBY">
<input type="image" src="https://www.paypalobjects.com/en_US/i/btn/btn_donate_SM.gif" width="70" height="21" border="0" name="submit" alt="PayPal - The safer, easier way to pay online!">
<img alt="" border="0" src="https://www.paypalobjects.com/en_US/i/scr/pixel.gif" width="1" height="1">
</form>';
        return $message;
    }

}
?>