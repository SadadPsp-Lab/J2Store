<?php
defined('_JEXEC') or die('Restricted access');
?>
<form action="https://banktest.ir/gateway/melli/purchase" method="post" name="adminForm" enctype="multipart/form-data">
    <input type="hidden" name="Token" value="<?= $vars->sadad_token ?>">
	<p>درگاه پرداخت سداد بانک ملی ایران</p>
	<br/>
    <input type="submit" class="j2store_cart_button button btn btn-primary" value="<?php echo JText::_($vars->button_text); ?>" />
</form>