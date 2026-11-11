<?php

use Moloni\Error;
use Moloni\Tools;

?>
<div class="alert alert-warning">
    <?= htmlspecialchars(Error::$error['message'] ?? 'Ligacao Moloni indisponivel.', ENT_QUOTES, 'UTF-8') ?>
</div>
<p>
    <a href="<?= htmlspecialchars(Tools::genURL(), ENT_QUOTES, 'UTF-8') ?>">Tentar novamente</a>
    |
    <a href="<?= htmlspecialchars(Tools::genURL('logout'), ENT_QUOTES, 'UTF-8') ?>">Desligar conta</a>
</p>
