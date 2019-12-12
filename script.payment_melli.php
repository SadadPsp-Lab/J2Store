<?php
/**
 * Created by PhpStorm.
 * User: mohsen
 * Date: 5/8/16
 * Time: 2:36 PM
 */
// no direct access
defined('_JEXEC') or die('Restricted access');

class plgJ2StorePayment_melliInstallerScript {

    function preflight( $type, $parent ) {

        if(!JComponentHelper::isEnabled('com_j2store')) {
            Jerror::raiseWarning(null, 'کامپوننت j2store نصب نیست. لطفا قبل از نصب این پلاگین j2store را نصب کنید!');
            return false;
        }

        jimport('joomla.filesystem.file');
        $version_file = JPATH_ADMINISTRATOR.'/components/com_j2store/version.php';
        if (JFile::exists ( $version_file )) {
            require_once($version_file);
            // abort if the current J2Store release is older
            if (version_compare ( J2STORE_VERSION, '2.7.3', 'lt' )) {
                Jerror::raiseWarning ( null, 'شما در حال استفاده از یک نسخه قدیمی از j2store هستید!' );
                return false;
            }
        } else {
            Jerror::raiseWarning ( null, 'مطمین شوید که j2store را درست نصب کرده اید!' );
            return false;
        }
    }

}
?>