<?php
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

    <h2 class="nav-tab-wrapper">
        <a href="#" class="nav-tab nav-tab-active" data-tab="import"><?php _e('Importar', 'md-import-force'); ?></a>
        <a href="#" class="nav-tab" data-tab="log"><?php _e('Log de Errores', 'md-import-force'); ?></a>
    </h2>

    <div class="md-import-force-container">
        <div id="tab-import" class="tab-content active">
            <div class="md-import-force-upload-section">
                <h2><?php _e('Importar Contenido', 'md-import-force'); ?></h2>

                <form id="md-import-force-form" method="post" enctype="multipart/form-data">
                    <div class="form-field">
                        <label for="import_file"><?php _e('Seleccionar archivo JSON:', 'md-import-force'); ?></label>
                        <input type="file"
                               id="import_file"
                               name="import_file"
                               required>
                    </div>

                    <p class="description"><?php _e('Selecciona un archivo .ZIP o .JSON para importar', 'md-import-force'); ?></p>

                    <div class="submit-button">
                        <button type="submit" class="button button-primary" id="md-import-force-import-button">
                            <?php _e('Importar', 'md-import-force'); ?>
                        </button>
                        <button type="button" class="button button-secondary" id="md-import-force-preview-button">
                            <?php _e('Previsualizar', 'md-import-force'); ?>
                        </button>
                    </div>
                </form>
            </div>

            <div id="md-import-force-messages" class="md-import-force-messages"></div>

            <div id="md-import-force-preview-area" class="md-import-force-preview-area" style="display: none;">
                <h3><?php _e('Previsualización', 'md-import-force'); ?></h3>
                <div id="md-import-force-preview-content"></div>
            </div>

            <div id="md-import-force-current-item" class="md-import-force-current-item" style="display: none;">
                <div class="current-item-title"><?php _e('Importando:', 'md-import-force'); ?> <span id="current-item-info">Preparando importación...</span></div>
                <div class="progress-stats">
                    <span id="progress-count">0</span> / <span id="progress-total">0</span>
                    (<span id="progress-percent">0%</span>)
                </div>
            </div>

            <div id="md-import-force-progress" class="md-import-force-progress" style="display: none;">
                <div class="progress-bar"></div>
            </div>
        </div>

        <div id="tab-log" class="tab-content" style="display: none;">
            <h2><?php _e('Log de Errores de PHP', 'md-import-force'); ?></h2>
            <button type="button" class="button button-secondary" id="md-import-force-refresh-log"><?php _e('Actualizar Log', 'md-import-force'); ?></button>
            <button type="button" class="button button-secondary" id="md-import-force-clear-log"><?php _e('Limpiar Log', 'md-import-force'); ?></button>
            <pre id="md-import-force-log-content" style="background-color: #f1f1f1; padding: 10px; border: 1px solid #ccc; max-height: 500px; overflow-y: scroll; white-space: pre-wrap; word-wrap: break-word;"></pre>
        </div>
    </div>
</div>
<style>
    .md-import-force-container .tab-content {
        margin-top: 20px;
    }
    .md-import-force-container .nav-tab-wrapper {
        margin-bottom: 0;
    }
    .md-import-force-container .nav-tab {
        padding: 10px 15px;
        font-size: 15px;
    }
    .md-import-force-container .nav-tab-active {
        border-bottom-color: #fff;
        background-color: #fff;
    }
</style>
