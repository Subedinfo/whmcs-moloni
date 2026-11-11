<?php

use Moloni\Tools;

?>
<p>
    <a href="<?= htmlspecialchars($this->downloadUrl, ENT_QUOTES, 'UTF-8') ?>">Descarregar PDF</a>
    | <a href="<?= htmlspecialchars(Tools::genURL('docs'), ENT_QUOTES, 'UTF-8') ?>">Voltar aos documentos</a>
</p>
<script>
    window.location.replace(<?= json_encode($this->downloadUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
</script>
