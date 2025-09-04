$(document).ready(function() {

    // Função para resetar o formulário e o modal
    function resetUserModal() {
        $('#userForm')[0].reset();
        $('#userModalLabel').text('Adicionar Usuário');
        $('#userAction').val('add_user');
        $('#userId').val('');
        $('input[name="unidades[]"]').prop('checked', false);
        $('input[name="operacoes[]"]').prop('checked', false);
    }

    // Ao clicar no botão 'Adicionar Usuário'
    $('#userModal').on('show.bs.modal', function(event) {
        if ($(event.relatedTarget).hasClass('btn-primary')) {
            // Se for o botão de adicionar, resetar o modal
            resetUserModal();
        }
    });

    // Editar usuário - Ao clicar no botão 'Editar'
    $('.edit-user').click(function() {
        const userId = $(this).data('id');

        // Resetar o formulário antes de carregar os dados
        resetUserModal();
        
        // Requisição AJAX para buscar os dados do usuário
        $.ajax({
            url: 'get_user.php',
            method: 'GET',
            data: { id: userId },
            dataType: 'json',
            success: function(response) {
                if (response.error) {
                    alert('Erro: ' + response.error);
                    return;
                }
                
                $('#userModalLabel').text('Editar Usuário');
                $('#userAction').val('edit_user');
                $('#userId').val(response.user.id);
                $('#nome').val(response.user.nome);
                $('#email').val(response.user.email);
                $('#tipo').val(response.user.tipo);
                $('#ativo').prop('checked', response.user.ativo == 1);
                
                // Marcar as permissões de unidades do usuário
                response.unidades.forEach(function(unidadeId) {
                    $(`input[name="unidades[]"][value="${unidadeId}"]`).prop('checked', true);
                });
                
                // Marcar as permissões de operações do usuário
                response.operacoes.forEach(function(operacaoId) {
                    $(`input[name="operacoes[]"][value="${operacaoId}"]`).prop('checked', true);
                });

                // Exibir o modal de edição
                $('#userModal').modal('show');
            },
            error: function() {
                alert('Erro ao carregar dados do usuário.');
            }
        });
    });
    
    // Excluir usuário - Ao clicar no botão 'Excluir'
    $('.delete-user').click(function() {
        const userId = $(this).data('id');
        $('#deleteUserId').val(userId);
        $('#confirmModal').modal('show');
    });
    
    // Resetar o modal de usuário ao ser fechado
    $('#userModal').on('hidden.bs.modal', function() {
        resetUserModal();
    });
});