<?php

use Moloni\Tools;

?>
<section id="moloni">
    <?php
    $activeTab = 'docs';
    include MOLONI_TEMPLATE_PATH . 'Blocks/header.php';
    include MOLONI_TEMPLATE_PATH . 'Blocks/messages.php';
    include MOLONI_TEMPLATE_PATH . 'Blocks/navbar.php';
    ?>
    <p>
        Dados Moloni em cache até 5 minutos. A pesquisa abrange as faturas WHMCS e os dados Moloni já consultados.
        <button type="button" class="btn waves-effect waves-light" id="moloni-documents-refresh">Atualizar página</button>
    </p>
    <div id="moloni-documents-warning" class="alert alert-warning" style="display:none" role="status"></div>
    <table class="highlight display moloniTable" width="100%">
        <thead>
        <tr>
            <th>Número</th><th>Cliente</th><th>Série</th><th>Data</th>
            <th>Estado</th><th>Total</th><th>Ações</th>
        </tr>
        </thead>
        <tbody></tbody>
    </table>
</section>
<?php include MOLONI_TEMPLATE_PATH . 'Blocks/footer.php'; ?>
<script>
    $(document).ready(function() {
        var refresh = false;
        var warning = $('#moloni-documents-warning');
        var fields = ['id', 'name', 'documentset', 'date', 'status', 'total'];
        var index = fields.indexOf(localStorage.getItem('docsIndex'));
        var order = localStorage.getItem('docsOrder') === 'descending' ? 'desc' : 'asc';
        var table = $('.moloniTable').dataTable({
            processing: true,
            serverSide: true,
            searchDelay: 500,
            order: [[index < 0 ? 0 : index, order]],
            pageLength: 10,
            lengthMenu: [10, 25],
            ajax: {
                url: <?= json_encode(Tools::genURL('docs'), JSON_HEX_TAG | JSON_HEX_AMP) ?>,
                data: function(data) {
                    data.ajax = true;
                    data.refresh = refresh ? '1' : '0';
                    refresh = false;
                },
                dataSrc: function(response) {
                    warning.text(response.warning || '').toggle(!!response.warning);
                    return response.data;
                },
                error: function(xhr) {
                    var message = xhr.responseJSON && xhr.responseJSON.error;
                    warning.text(message || 'Não foi possível consultar os documentos. Tente novamente.').show();
                    $('.dataTables_processing').hide();
                }
            },
            columns: [
                {data: 'number'}, {data: 'name'}, {data: 'document_set'}, {data: 'date'},
                {data: 'status'}, {data: 'total'}, {data: 'actions', orderable: false, searchable: false}
            ],
            sPaginationType: 'simple_numbers',
            sDom: '<"top"<"MolSearch"f><"MolShowing"l>>rt<"bottom row"<"MolInfo col s6"i><"MolPagination col s6"p>><"clear">',
            language: {
                sLengthMenu: '_MENU_', sZeroRecords: 'Sem resultados encontrados',
                sInfo: 'A mostrar <b>_START_</b> - <b>_END_</b> de <b>_TOTAL_</b> Faturas',
                sInfoEmpty: 'Sem resultados para apresentar', sInfoFiltered: '(Filtrados de _MAX_)',
                sSearch: '', sSearchPlaceholder: 'Pesquisar...',
                oPaginate: {sPrevious: 'Anterior', sNext: 'Seguinte'}
            }
        });
        $('#moloni-documents-refresh').on('click', function() {
            refresh = true;
            table.fnDraw(false);
        });
        $('.moloniTable').on('order.dt', function() {
            var ordering = table.api().order()[0];
            localStorage.setItem('docsIndex', fields[ordering[0]]);
            localStorage.setItem('docsOrder', ordering[1] === 'asc' ? 'ascending' : 'descending');
        });
    });
</script>
