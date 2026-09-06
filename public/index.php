<?php
// public/index.php - Entry Point Principal da Aplicação
require_once __DIR__ . '/../api/config.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Controle de Formatura</title>
    
    <!-- Fonte Google Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- Estilos CSS do Sistema com Cache-Busting -->
    <link rel="stylesheet" href="css/style.css?v=<?= time() ?>">

    <!-- React 18 & ReactDOM UMD -->
    <script src="https://unpkg.com/react@18/umd/react.production.min.js" crossorigin></script>
    <script src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js" crossorigin></script>
    <!-- Babel Standalone para interpretar JSX no HostGator sem Node.js -->
    <script src="https://unpkg.com/@babel/standalone/babel.min.js"></script>
</head>
<body>
    <div id="root"></div>

    <!-- Aplicação React com JSX e Cache-Busting -->
    <script type="text/babel" src="js/app.js?v=<?= time() ?>"></script>
</body>
</html>
