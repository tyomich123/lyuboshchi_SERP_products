jQuery(document).ready(function($) {
    'use strict';

    var pollTimer = null;

    function formatDate(dateString) {
        if (!dateString) {
            return '';
        }
        var date = new Date(dateString.replace(' ', 'T'));
        if (isNaN(date.getTime())) {
            return dateString;
        }
        return date.toLocaleString();
    }

    function updateProgress() {
        $.ajax({
            url: sprAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'spr_recalculate_status',
                nonce: sprAdmin.nonce
            },
            success: function(response) {
                if (!response.success) {
                    return;
                }

                var state = response.data.state || {};
                var percent = response.data.percent || 0;
                var $progressFill = $('.spr-progress-fill');
                var $progressText = $('.spr-progress-text');
                var $button = $('#spr-recalculate-all');

                $progressFill.css('width', percent + '%');

                if (state.status === 'running') {
                    $button.prop('disabled', true);
                    $progressText.text('Оброблено ' + state.processed + ' з ' + state.total + ' (' + percent + '%). Оновлено: ' + formatDate(state.updated_at));
                    if (!pollTimer) {
                        pollTimer = setInterval(updateProgress, 5000);
                    }
                } else if (state.status === 'completed') {
                    $button.prop('disabled', false);
                    $progressText.text('Завершено: ' + state.processed + ' з ' + state.total + '. Завершено: ' + formatDate(state.finished_at));
                    if (pollTimer) {
                        clearInterval(pollTimer);
                        pollTimer = null;
                    }
                } else {
                    $button.prop('disabled', false);
                    $progressText.text('Перерахунок не запущено.');
                    if (pollTimer) {
                        clearInterval(pollTimer);
                        pollTimer = null;
                    }
                }
            }
        });
    }

    updateProgress();
    
    // Перерахунок релевантності
    $('#spr-recalculate-all').on('click', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var $spinner = $button.next('.spinner');
        var $message = $('.spr-message');
        
        $button.prop('disabled', true);
        $spinner.addClass('is-active');
        $message.text('');
        
        $.ajax({
            url: sprAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'spr_recalculate_all',
                nonce: sprAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    $message.html('<span style="color: green;">✓ ' + response.data.message + '</span>');
                    updateProgress();
                } else {
                    $message.html('<span style="color: red;">✗ ' + response.data.message + '</span>');
                }
            },
            error: function() {
                $message.html('<span style="color: red;">✗ Помилка при виконанні запиту</span>');
            },
            complete: function() {
                $button.prop('disabled', false);
                $spinner.removeClass('is-active');
            }
        });
    });
    
    // Очищення даних
    $('#spr-clear-data').on('click', function(e) {
        e.preventDefault();
        
        if (!confirm('Ви впевнені? Це видалить всі дані про перегляди та релевантність. Цю дію неможливо скасувати!')) {
            return;
        }
        
        var $button = $(this);
        var $message = $('.spr-clear-message');
        
        $button.prop('disabled', true);
        $message.text('');
        
        $.ajax({
            url: sprAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'spr_clear_data',
                nonce: sprAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    $message.html('<span style="color: green;">✓ ' + response.data.message + '</span>');
                    
                    // Перезавантаження сторінки через 2 секунди
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    $message.html('<span style="color: red;">✗ ' + response.data.message + '</span>');
                }
            },
            error: function() {
                $message.html('<span style="color: red;">✗ Помилка при виконанні запиту</span>');
            },
            complete: function() {
                $button.prop('disabled', false);
            }
        });
    });
});