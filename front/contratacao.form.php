<?php

use GlpiPlugin\Pca\Contratacao;

Session::checkRight(Contratacao::$rightname, READ);

$item = new Contratacao();

if (isset($_POST['add'])) {
    $item->check(-1, CREATE, $_POST);
    if ($id = $item->add($_POST)) {
        Html::redirect($item->getFormURLWithID($id));
    }
    Html::back();
} elseif (isset($_POST['update'])) {
    $item->check($_POST['id'], UPDATE);
    $item->update($_POST);
    Html::back();
} elseif (isset($_POST['delete'])) {
    $item->check($_POST['id'], DELETE);
    $item->delete($_POST);
    $item->redirectToList();
} elseif (isset($_POST['restore'])) {
    $item->check($_POST['id'], DELETE);
    $item->restore($_POST);
    $item->redirectToList();
} elseif (isset($_POST['purge'])) {
    $item->check($_POST['id'], PURGE);
    $item->delete($_POST, true);
    $item->redirectToList();
} else {
    $menus = ['management', Contratacao::class];
    Contratacao::displayFullPageForItem((int) ($_GET['id'] ?? 0), $menus, ['formoptions' => "data-track-changes=true"]);
}
