<?php
include('../../../inc/includes.php');
Session::checkRight('config', UPDATE);
Html::redirect(Plugin::getWebDir('beemoconnect') . '/front/config.php');
