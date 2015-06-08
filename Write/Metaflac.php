<?php

namespace GetId3\Write;

use GetId3\GetId3Core;

/////////////////////////////////////////////////////////////////
/// GetId3() by James Heinrich <info@getid3.org>               //
//  available at http://getid3.sourceforge.net                 //
//            or http://www.getid3.org                         //
/////////////////////////////////////////////////////////////////
// See readme.txt for more details                             //
/////////////////////////////////////////////////////////////////
//                                                             //
// write.metaflac.php                                          //
// module for writing metaflac tags                            //
// dependencies: /helperapps/metaflac.exe                      //
//                                                            ///
/////////////////////////////////////////////////////////////////

/**
 * module for writing metaflac tags
 *
 * @author James Heinrich <info@getid3.org>
 *
 * @link http://getid3.sourceforge.net
 * @link http://www.getid3.org
 *
 * @uses helperapps/metaflac.exe
 */
class Metaflac
{
    public $filename;
    public $tag_data;
    /**
     * @var array
     */
    public $warnings = array(); // any non-critical errors will be stored here
    /**
     * @var array
     */
    public $errors = array(); // any critical errors will be stored here

    /**
     * @return bool
     */
    public function __construct()
    {
        return true;
    }

    /**
     * @return bool
     */
    public function WriteMetaFLAC()
    {
        if (preg_match('#(1|ON)#i', ini_get('safe_mode'))) {
            $this->errors[] = 'PHP running in Safe Mode (backtick operator not available) - cannot call metaflac, tags not written';

            return false;
        }

        // Create file with new comments
        $tempcommentsfilename = tempnam(GetId3Core::getTempDir(), 'getID3');
        if (is_writable($tempcommentsfilename) && is_file($tempcommentsfilename) && ($fpcomments = fopen($tempcommentsfilename, 'wb'))) {
            foreach ($this->tag_data as $key => $value) {
                foreach ($value as $commentdata) {
                    fwrite($fpcomments, $this->CleanmetaflacName($key).'='.$commentdata."\n");
                }
            }
            fclose($fpcomments);
        } else {
            $this->errors[] = 'failed to open temporary tags file, tags not written - fopen("'.$tempcommentsfilename.'", "wb")';

            return false;
        }

        $oldignoreuserabort = ignore_user_abort(true);
        if (GetId3Core::environmentIsWindows()) {
            if (file_exists(GetId3Core::getHelperAppsDir().'metaflac.exe')) {
                //$commandline = '"'.GetId3Core::getHelperAppsDir().'metaflac.exe" --no-utf8-convert --remove-all-tags --import-tags-from="'.$tempcommentsfilename.'" "'.str_replace('/', '\\', $this->filename).'"';
                //  metaflac works fine if you copy-paste the above commandline into a command prompt,
                //  but refuses to work with `backtick` if there are "doublequotes" present around BOTH
                //  the metaflac pathname and the target filename. For whatever reason...??
                //  The solution is simply ensure that the metaflac pathname has no spaces,
                //  and therefore does not need to be quoted

                // On top of that, if error messages are not always captured properly under Windows
                // To at least see if there was a problem, compare file modification timestamps before and after writing
                clearstatcache();
                $timestampbeforewriting = filemtime($this->filename);

                $commandline = GetId3Core::getHelperAppsDir().'metaflac.exe --no-utf8-convert --remove-all-tags --import-tags-from='.escapeshellarg($tempcommentsfilename).' '.escapeshellarg($this->filename).' 2>&1';
                $metaflacError = `$commandline`;

                if (empty($metaflacError)) {
                    clearstatcache();
                    if ($timestampbeforewriting == filemtime($this->filename)) {
                        $metaflacError = 'File modification timestamp has not changed - it looks like the tags were not written';
                    }
                }
            } else {
                $metaflacError = 'metaflac.exe not found in '.GetId3Core::getHelperAppsDir();
            }
        } else {

            // It's simpler on *nix
            $commandline = 'metaflac --no-utf8-convert --remove-all-tags --import-tags-from='.escapeshellarg($tempcommentsfilename).' '.escapeshellarg($this->filename).' 2>&1';
            $metaflacError = `$commandline`;
        }

        // Remove temporary comments file
        unlink($tempcommentsfilename);
        ignore_user_abort($oldignoreuserabort);

        if (!empty($metaflacError)) {
            $this->errors[] = 'System call to metaflac failed with this message returned: '."\n\n".$metaflacError;

            return false;
        }

        return true;
    }

    /**
     * @return bool
     */
    public function DeleteMetaFLAC()
    {
        if (preg_match('#(1|ON)#i', ini_get('safe_mode'))) {
            $this->errors[] = 'PHP running in Safe Mode (backtick operator not available) - cannot call metaflac, tags not deleted';

            return false;
        }

        $oldignoreuserabort = ignore_user_abort(true);
        if (GetId3Core::environmentIsWindows()) {
            if (file_exists(GetId3Core::getHelperAppsDir().'metaflac.exe')) {
                // To at least see if there was a problem, compare file modification timestamps before and after writing
                clearstatcache();
                $timestampbeforewriting = filemtime($this->filename);

                $commandline = GetId3Core::getHelperAppsDir().'metaflac.exe --remove-all-tags "'.$this->filename.'" 2>&1';
                $metaflacError = `$commandline`;

                if (empty($metaflacError)) {
                    clearstatcache();
                    if ($timestampbeforewriting == filemtime($this->filename)) {
                        $metaflacError = 'File modification timestamp has not changed - it looks like the tags were not deleted';
                    }
                }
            } else {
                $metaflacError = 'metaflac.exe not found in '.GetId3Core::getHelperAppsDir();
            }
        } else {

            // It's simpler on *nix
            $commandline = 'metaflac --remove-all-tags "'.$this->filename.'" 2>&1';
            $metaflacError = `$commandline`;
        }

        ignore_user_abort($oldignoreuserabort);

        if (!empty($metaflacError)) {
            $this->errors[] = 'System call to metaflac failed with this message returned: '."\n\n".$metaflacError;

            return false;
        }

        return true;
    }

    /**
     * @param  type $originalcommentname
     *
     * @return type
     */
    public function CleanmetaflacName($originalcommentname)
    {
        // A case-insensitive field name that may consist of ASCII 0x20 through 0x7D, 0x3D ('=') excluded.
        // ASCII 0x41 through 0x5A inclusive (A-Z) is to be considered equivalent to ASCII 0x61 through
        // 0x7A inclusive (a-z).

        // replace invalid chars with a space, return uppercase text
        // Thanks Chris Bolt <chris-getid3Øbolt*cx> for improving this function
        // note: *reg_replace() replaces nulls with empty string (not space)
        return strtoupper(preg_replace('#[^ -<>-}]#', ' ', str_replace("\x00", ' ', $originalcommentname)));
    }
}
