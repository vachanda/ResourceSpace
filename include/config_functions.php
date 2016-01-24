<?php
/**
 * Helper and rendering function for the configuration pages in the team center
 * 
 * @package ResourceSpace
 * @subpackage Includes
 */

/**
 * Validate the given field.
 * 
 * If the field validates, this function will store it in the provided conifguration
 * module and key.
 * 
 * @param string $fieldname Name of field (provided to the render functions)
 * @param string $modulename Module name to store the field in.
 * @param string $modulekey Module key
 * @param string $type Validation patthern: (bool,safe,float,int,email,regex)
 * @param string $required Optional required flag.  Defaults to true.
 * @param string $pattern If $type is 'regex' the regex pattern to use.
 * @return bool Returns true if the field was stored in the config database.
 */
function validate_field($fieldname, $modulename, $modulekey, $type, $required=true, $pattern=''){
    global $errorfields, $lang;
    $value = getvalescaped($fieldname, '');
    if ($value=='' && $required==true){
        $errorfields[$fieldname]=$lang['cfg-err-fieldrequired'];
        return false;
    }
    elseif ($value=='' && $required==false){
        set_module_config_key($modulename, $modulekey, $value); 
    }
    else {
        switch ($type){
            case 'safe':
                if (!preg_match('/^.+$/', $value)){
                    $errorfields[$fieldname] = $lang['cfg-err-fieldsafe'];
                    return false;
                }
                break;
            case 'float':
                if (!preg_match('/^[\d]+(\.[\d]*)?$/', $value)){
                    $errorfields[$fieldname] = $lang['cfg-err-fieldnumeric'];
                    return false;
                }
                break;
            case 'int':
                if (!preg_match('/^[\d]+$/', $value)){
                    $errorfields[$fieldname] = $lang['cfg-err-fieldnumeric'];
                    return false;
                }
                break;
            case 'email':
                if (!preg_match('/^[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,4}$/i', $value)){
                    $errorfields[$fieldname] = $lang['cfg-err-fieldemail'];
                    return false;
                }
                break;
            case 'regex':
                if (!preg_match($pattern, $value)){
                    $errorfields[$fieldname] = $lang['cfg-err-fieldsafe'];
                    return false;
                }
                break;
            case 'bool':
                if (strtolower($value)=='true')
                    $value=true;
                elseif (strtolower($value)=='false')
                    $value=false;
                else {
                    $errorfields[$fieldname] = $lang['cfg-err-fieldsafe'];
                    return false;
                }
                break;
        }
       set_module_config_key($modulename, $modulekey, $value);
       return true;             
    }
}
/**
 * Renders a select element.
 * 
 * Takes an array of options (as returned from sql_query and returns a valid 
 * select element.  The query must have a column aliased as value and label.  
 * Option groups can be created as well with the optional $groupby parameter.
 * This function retrieves a language field in the form of 
 * $lang['cfg-<fieldname>'] to use for the element label.
 * 
 * <code>
 * $options = sql_select("SELECT name as label, ref as value FROM resource_types");
 * 
 * render_select_option('myfield', $options, 18);
 * </code>
 * 
 * @param string $fieldname Name to use for the field.
 * @param string $opt_array Array of options to fill the select with
 * @param mixed $selected If matches value the option is marked as selected
 * @param string $groupby Column to group by
 * @return string HTML output.
 */
function render_select_option($fieldname, $opt_array, $selected, $groupby=''){
    global $errorfields, $lang;
    $output = '';
    $output .= "<tr><th><label for=\"$fieldname\">".$lang['cfg-'.$fieldname]."</label></th>";
    $output .= "<td><select name=\"$fieldname\">";
    if ($groupby!=''){
        $cur_group = $opt_array[0][$groupby];
        $output .= "<optgroup label=\"$cur_group\">";
    }
    foreach ($opt_array as $option){
        if ($groupby!='' && $cur_group!=$option[$groupby]){
          $cur_group = $option[$groupby];
          $output .= "</optgroup><optgroup label=\"$cur_group\">";            
        }
        $output .= "<option ";
        $output .= $option['value']==$selected?'selected="selected" ':'';
        $output .= "value=\"{$option['value']}\">{$option['label']}</option>";
    }
    $output .= '</optgroup>';
    $output .= isset($errorfields[$fieldname])?'<span class="error">* '.$errorfields[$fieldname].'</span>':'';
    $output .= '</td></tr>';
    return $output;
}

/**
 * Render a yes/no field with the given fieldname.
 * 
 * This function will use $lang['cfg-<fieldname>'] as the text label for the
 * element.
 * @param string $fieldname Name of field.
 * @param bool $value Current field value
 * @return string HTML Output
 */
function render_bool_option($fieldname, $value){
    global $errorfields, $lang;
    $output = '';
    $output .= "<tr><th><label for=\"$fieldname\">".$lang['cfg-'.$fieldname]."</label></th>";
    $output .= "<td><select name=\"$fieldname\">";
    $output .= "<option value='true' ";
    $output .= $value?'selected':'';
    $output .= ">Yes</option>";
    $output .= "<option value='false' ";
    $output .= !$value?'selected':'';
    $output .= ">No</option></select>";
    $output .= isset($errorfields[$fieldname])?'<span class="error">* '.$errorfields[$fieldname].'</span>':'';
    $output .= "</td></tr>";
    return $output;
}

/**
 * Renders a text field for a given field name.
 * 
 * Uses $lang['cfg-<fieldname>'] as the field label.
 * 
 * @param string $fieldname Name of field
 * @param string $value Current field value
 * @param int $size Size of text field, optional, defaults to 20
 * @param string $units Optional units parameter. Displays to right of text field.
 * @return string HTML Output
 */
function render_text_option($fieldname, $value, $size=20, $units=''){
    global $errorfields, $lang;
    if (isset($errorfields[$fieldname]) && isset($_POST[$fieldname]))
        $value = $_POST[$fieldname];
    $output = '';
    $output .= "<tr><th><label for=\"$fieldname\">".$lang['cfg-'.$fieldname]."</label></th>";
    $output .= "<td><input type=\"text\" value=\"$value\" size=\"$size\" name=\"$fieldname\"/> $units ";
    $output .= isset($errorfields[$fieldname])?'<span class="error">* '.$errorfields[$fieldname].'</span>':'';
    $output .= "</td></tr>";
    return $output;
}


/**
* Save/ Update config option
*
* @param  integer  $user_id      Current user ID
* @param  string   $param_name   Parameter name
* @param  string   $param_value  Parameter value
*
* @return boolean
*/
function set_config_option($user_id, $param_name, $param_value)
    {
    // We do allow for param values to be empty strings or 0 (zero)
    if(empty($param_name) || is_null($param_value))
        {
        return false;
        }

    // Prepare the value before inserting it
    $param_value = config_clean($param_value);
    $param_value = escape_check($param_value);

    $query = sprintf('
            INSERT INTO user_preferences (
                                             user,
                                             parameter,
                                             `value`
                                         )
                 VALUES (
                            %s,     # user
                            \'%s\', # parameter
                            \'%s\'  # value
                        );
        ',
        is_null($user_id) ? 'NULL' : '\'' . $user_id . '\'',
        $param_name,
        $param_value
    );

    $current_param_value = null;
    if(get_config_option($user_id, $param_name, $current_param_value))
        {
        if($current_param_value == $param_value)
            {
            return true;
            }

        $query = sprintf('
                UPDATE user_preferences
                   SET `value` = \'%s\'
                 WHERE user %s
                   AND parameter = \'%s\';
            ',
            $param_value,
            is_null($user_id) ? 'IS NULL' : '= \'' . $user_id . '\'',
            $param_name
        );
        }

    sql_query($query);

    return true;
    }


/**
* Get config option from database
* 
* @param  integer  $user_id         Current user ID
* @param  string   $name            Parameter name
* @param  string   $returned_value  If a value does exist it will be returned through
*                                   this parameter which is passed by reference
* @return boolean
*/
function get_config_option($user_id, $name, &$returned_value)
    {
    if(trim($name) === '')
        {
        return false;
        }

    $query = sprintf('
            SELECT `value`
              FROM user_preferences
             WHERE user %s
               AND parameter = "%s";
        ',
        is_null($user_id) ? 'IS NULL' : '= \'' . escape_check($user_id) . '\'',
        $name
    );
    $config_option = sql_value($query, null);

    if(is_null($config_option))
        {
        return false;
        }

    $returned_value = unescape($config_option);

    return true;
    }


/**
* Get config option from database for a specific user or system wide
* 
* @param  integer  $user_id           Current user ID. Can also be null to retrieve system wide config options
* @param  array    $returned_options  If a value does exist it will be returned through
*                                     this parameter which is passed by reference
* @return boolean
*/
function get_config_options($user_id, array &$returned_options)
    {
    $query = sprintf('
            SELECT parameter,
                   `value`
              FROM user_preferences
             WHERE %s;
        ',
        is_null($user_id) ? 'user IS NULL' : 'user = \'' . escape_check($user_id) . '\''
    );
    $config_options = sql_query($query);

    if(empty($config_options))
        {
        return false;
        }

    $returned_options = $config_options;

    return true;
    }


/**
* Process configuration options from database
* either system wide or user specific by setting
* the global variable
*
* @param int $user_id
*
* @return bool true
*/
function process_config_options($user_id = null)
    {
    $config_options = array();

    if(get_config_options($user_id, $config_options))
        {
        foreach($config_options as $config_option)
            {
            $param_value = $config_option['value'];

            // Prepare the value since everything is stored as a string
            switch($param_value)
                {
                case '1':
                case '0':
                    $param_value = (bool) $param_value;
                    break;

                case is_numeric($param_value):
                    $param_value = (int) $param_value;
                    break;
                
                default:
                    // we assume it is a string and set as-is
                    break;
                }

            $GLOBALS[$config_option['parameter']] = $param_value;
            }
        }

    return true;
    }


/**
 * Utility function to "clean" the passed $config. Cleaning consists of two parts:
 *  *    Suppressing really simple XSS attacks by refusing to allow strings
 *       containing the characters "<script" in upper, lower or mixed case.
 *  *    Unescaping instances of "'" and '"' that have been escaped by the
 *       lovely magic_quotes_gpc facility, if it's on.
 *
 * @param $config mixed thing to be cleaned.
 * @return a cleaned version of $config.
 */
function config_clean($config)
    {
    if (is_array($config))
        {
        foreach ($config as &$item)
            {
            $item = config_clean($item);
            }
        }
    elseif (is_string($config))
        {
        if (strpos(strtolower($config),"<script") !== false)
            {
            $config = '';
            }
        if (get_magic_quotes_gpc())
            {
            $config = stripslashes($config);
            }
        }
    return $config;
    }


/**
 * Generate arbitrary html
 *
 * @param string $content arbitrary HTML 
 */
function config_html($content)
    {
    echo $content;
    }


/**
 * Return a data structure that will instruct the configuration page generator functions to add
 * arbitrary HTML
 *
 * @param string $content
 */
function config_add_html($content)
    {
    return array('html',$content);
    }


 /**
 * Generate an html text entry or password block
 *
 * @param string $name the name of the text block. Usually the name of the config variable being set.
 * @param string $label the user text displayed to label the text block. Usually a $lang string.
 * @param string $current the current value of the config variable being set.
 * @param boolean $password whether this is a "normal" text-entry field or a password-style
 *          field. Defaulted to false.
 * @param integer $width the width of the input field in pixels. Default: 300.
 */
function config_text_input($name, $label, $current, $password = false, $width = 300, $textarea = false, $title = null, $autosave = false)
    {
    global $lang;

    if(is_null($title))
        {
        // This is how it was used on plugins setup page. Makes sense for developers when trying to debug and not much for non-technical users
        $title = str_replace('%cvn', $name, $lang['plugins-configvar']);
        }
    ?>

    <div class="Question">
        <label for="<?php echo $name; ?>" title="<?php echo $title; ?>"><?php echo $label; ?></label>
    <?php
    if($autosave)
        {
        ?>
        <div class="AutoSaveStatus">
            <span id="AutoSaveStatus-<?php echo $name; ?>" style="display:none;"></span>
        </div>
        <?php
        }

    if($textarea == false)
        {
        ?>
        <input id="<?php echo $name; ?>"
               name="<?php echo $name; ?>"
               type="<?php echo $password ? 'password' : 'text'; ?>"
               value="<?php echo htmlspecialchars($current, ENT_QUOTES); ?>"
               <?php if($autosave) { ?>onFocusOut="AutoSaveConfigOption('<?php echo $name; ?>');"<?php } ?>
               style="width:<?php echo $width; ?>px" />
        <?php
        }
    else
        {
        ?>
        <textarea id="<?php echo $name; ?>" name="<?php echo $name; ?>" style="width:<?php echo $width; ?>px"><?php echo htmlspecialchars($current, ENT_QUOTES); ?></textarea>
        <?php
        }
        ?>
    </div>
    <div class="clearerleft"></div>
    <?php
    }

/**
 * Return a data structure that will instruct the configuration page generator functions to
 * add a text entry configuration variable to the setup page.
 *
 * @param string $config_var the name of the configuration variable to be added.
 * @param string $label the user text displayed to label the text block. Usually a $lang string.
 * @param boolean $password whether this is a "normal" text-entry field or a password-style
 *          field. Defaulted to false.
 * @param integer $width the width of the input field in pixels. Default: 300.
 */
function config_add_text_input($config_var, $label, $password = false, $width = 300, $textarea = false, $title = null, $autosave = false)
    {
    return array('text_input', $config_var, $label, $password, $width, $textarea, $title, $autosave);
    }


/**
 * Generate an html single-select + options block
 *
 * @param string        $name      The name of the select block. Usually the name of the config variable being set.
 * @param string        $label     The user text displayed to label the select block. Usually a $lang string.
 * @param string        $current   The current value of the config variable being set.
 * @param string array  $choices   The array of the alternatives -- the options in the select block. The keys
 *                                 are used as the values of the options, and the values are the alternatives the user sees. (But
 *                                 see $usekeys, below.) Usually a $lang entry whose value is an array of strings.
 * @param boolean       $usekeys   Tells whether to use the keys from $choices as the values of the options. If set
 *                                 to false the values from $choices will be used for both the values of the options and the text
 *                                 the user sees. Defaulted to true.
 * @param integer       $width     The width of the input field in pixels. Default: 300.
 * @param string        $title     Title to be used for the label title. Default: null
 * @param boolean       $autosave  Flag to say whether the there should be an auto save message feedback through JS. Default: false
 *                                 Note: onChange event will call AutoSaveConfigOption([option name])
 */
function config_single_select($name, $label, $current, $choices, $usekeys = true, $width = 300, $title = null, $autosave = false)
    {
    global $lang;
    
    if(is_null($title))
        {
        // This is how it was used on plugins setup page. Makes sense for developers when trying to debug and not much for non-technical users
        $title = str_replace('%cvn', $name, $lang['plugins-configvar']);
        }
    ?>
    <div class="Question">
        <label for="<?php echo $name; ?>" title="<?php echo $title; ?>"><?php echo $label; ?></label>
        <?php
        if($autosave)
            {
            ?>
            <div class="AutoSaveStatus">
                <span id="AutoSaveStatus-<?php echo $name; ?>" style="display:none;"></span>
            </div>
            <?php
            }
        ?>
        <select id="<?php echo $name; ?>"
                name="<?php echo $name; ?>"
                <?php if($autosave) { ?> onChange="AutoSaveConfigOption('<?php echo $name; ?>');"<?php } ?>
                style="width:<?php echo $width; ?>px">
        <?php
        foreach($choices as $key => $choice)
            {
            $value = $usekeys ? $key : $choice;
            echo '<option value="' . $value . '"' . (($current == $value) ? ' selected' : '') . ">$choice</option>";
            }
        ?>
        </select>
    </div>
    <div class="clearerleft"></div>
    <?php
    }


/**
 * Return a data structure that will instruct the configuration page generator functions to
 * add a single select configuration variable to the setup page.
 *
 * @param string $config_var the name of the configuration variable to be added.
 * @param string $label the user text displayed to label the select block. Usually a $lang string.
 * @param string array $choices the array of the alternatives -- the options in the select block. The keys
 *          are used as the values of the options, and the values are the alternatives the user sees. (But
 *          see $usekeys, below.) Usually a $lang entry whose value is an array of strings.
 * @param boolean $usekeys tells whether to use the keys from $choices as the values of the options. If set
 *          to false the values from $choices will be used for both the values of the options and the text
 *          the user sees. Defaulted to true.
 * @param integer $width the width of the input field in pixels. Default: 300.
 */
function config_add_single_select($config_var, $label, $choices = '', $usekeys = true, $width = 300, $title = null, $autosave = false)
    {
    return array('single_select', $config_var, $label, $choices, $usekeys, $width, $title, $autosave);
    }


/**
 * Generate an html boolean select block
 *
 * @param string        $name      The name of the select block. Usually the name of the config variable being set.
 * @param string        $label     The user text displayed to label the select block. Usually a $lang string.
 * @param boolean       $current   The current value (true or false) of the config variable being set.
 * @param string array  $choices   Array of the text to display for the two choices: False and True. Defaults
 *                                 to array('False', 'True') in the local language.
 * @param integer       $width     The width of the input field in pixels. Default: 300.
 * @param string        $title     Title to be used for the label title. Default: null
 * @param boolean       $autosave  Flag to say whether the there should be an auto save message feedback through JS. Default: false
 *                                 Note: onChange event will call AutoSaveConfigOption([option name])
 */
function config_boolean_select($name, $label, $current, $choices = '', $width = 300, $title = null, $autosave = false)
    {
    global $lang;

    if($choices == '')
        {
        $choices = $lang['false-true'];
        }

    if(is_null($title))
        {
        // This is how it was used on plugins setup page. Makes sense for developers when trying to debug and not much for non-technical users
        $title = str_replace('%cvn', $name, $lang['plugins-configvar']);
        }
    ?>
    <div class="Question">
        <label for="<?php echo $name; ?>" title="<?php echo $title; ?>"><?php echo $label; ?></label>

        <?php
        if($autosave)
            {
            ?>
            <div class="AutoSaveStatus">
                <span id="AutoSaveStatus-<?php echo $name; ?>" style="display:none;"></span>
            </div>
            <?php
            }
            ?>
        <select id="<?php echo $name; ?>"
                name="<?php echo $name; ?>"
                <?php if($autosave) { ?> onChange="AutoSaveConfigOption('<?php echo $name; ?>');"<?php } ?>
                style="width:<?php echo $width; ?>px">
            <option value="1"<?php if($current == '1') { ?> selected<?php } ?>><?php echo $choices[1]; ?></option>
            <option value="0"<?php if($current == '0') { ?> selected<?php } ?>><?php echo $choices[0]; ?></option>
        </select>
    </div>
    <div class="clearerleft"></div>
    <?php
    }


/**
 * Return a data structure that will instruct the configuration page generator functions to
 * add a boolean configuration variable to the setup page.
 *
 * @param string $config_var the name of the configuration variable to be added.
 * @param string $label the user text displayed to label the select block. Usually a $lang string.
 * @param string array $choices array of the text to display for the two choices: False and True. Defaults
 *          to array('False', 'True') in the local language.
 * @param integer $width the width of the input field in pixels. Default: 300.
 */
function config_add_boolean_select($config_var, $label, $choices = '', $width = 300, $title = null, $autosave = false)
    {
    return array('boolean_select', $config_var, $label, $choices, $width, $title, $autosave);
    }


/**
* Generate Javascript function used for auto saving individual config options
*
* @param string $post_url URL to where the data will be posted
*/
function config_generate_AutoSaveConfigOption_function($post_url)
    {
    global $lang;
    ?>
    
    <script>
    function AutoSaveConfigOption(option_name)
        {
        jQuery('#AutoSaveStatus-' + option_name).html('<?php echo $lang["saving"]; ?>');
        jQuery('#AutoSaveStatus-' + option_name).show();

        var option_value = jQuery('#' + option_name).val();
        var post_url  = '<?php echo $post_url; ?>';
        var post_data = {
            ajax: true,
            autosave: true,
            autosave_option_name: option_name,
            autosave_option_value: option_value
        };

        jQuery.post(post_url, post_data, function(response) {

            if(response.success === true)
                {
                jQuery('#AutoSaveStatus-' + option_name).html('<?php echo $lang["saved"]; ?>');
                jQuery('#AutoSaveStatus-' + option_name).fadeOut('slow');
                }
            else if(response.success === false && response.message && response.message.length > 0)
                {
                jQuery('#AutoSaveStatus-' + option_name).html('<?php echo $lang["save-error"]; ?> ' + response.message);
                }
            else
                {
                jQuery('#AutoSaveStatus-' + option_name).html('<?php echo $lang["save-error"]; ?>');
                }

        }, 'json');

        return true;
        }
    </script>
    
    <?php
    }


/**
* Generates HTML foreach element found in the page definition
* 
* @param array $page_def Array of all elements for which we need to generate HTML
*/
function config_generate_html(array $page_def)
    {
    global $lang;

    foreach($page_def as $def)
        {
        switch($def[0])
            {
            case 'html':
                config_html($def[1]);
                break;

            case 'text_input':
                config_text_input($def[1], $def[2], $GLOBALS[$def[1]], $def[3], $def[4], $def[5], $def[6], $def[7]);
                break;

            case 'boolean_select':
                config_boolean_select($def[1], $def[2], $GLOBALS[$def[1]], $def[3], $def[4], $def[5], $def[6]);
                break;

            case 'single_select':
                config_single_select($def[1], $def[2], $GLOBALS[$def[1]], $def[3], $def[4], $def[5], $def[6], $def[7]);
                break;
            }
        }
    }