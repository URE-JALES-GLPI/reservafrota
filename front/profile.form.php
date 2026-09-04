<?php

use GlpiPlugin\Reservafrota\Profile;

// Quem edita perfis precisa do direito nativo "profile" com UPDATE.
Session::checkRight('profile', UPDATE);

// O token CSRF já é validado automaticamente pelo GLPI (plugin csrf_compliant)
// antes deste script rodar — NÃO revalidamos aqui (seria uso duplo do token).
if (isset($_POST['update'], $_POST['profiles_id'])) {
    $profiles_id = (int) $_POST['profiles_id'];
    $rights      = is_array($_POST['rights'] ?? null) ? $_POST['rights'] : [];

    Profile::saveRights($profiles_id, $rights);

    // Aplica os novos direitos na sessão ativa imediatamente (sem relogar).
    Profile::changeProfile();

    Session::addMessageAfterRedirect(
        __('Permissões do Agendamento de Carros atualizadas.', 'reservafrota'),
        true,
        INFO
    );
}

Html::back();