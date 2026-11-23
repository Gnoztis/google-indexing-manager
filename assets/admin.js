/* global jQuery, gimData */
jQuery( document ).ready( function ( $ ) {
    'use strict';

    var $progress = $( '#gim-progress' );

    function showMessage( text, type, sticky ) {
        if ( ! $progress.length ) {
            return;
        }

        var cls = 'gim-progress';

        if ( type === 'error' ) {
            cls += ' gim-progress--error';
        } else if ( type === 'success' ) {
            cls += ' gim-progress--success';
        }

        $progress
            .attr( 'class', cls )
            .text( text )
            .show();

        if ( ! sticky ) {
            setTimeout( function () {
                $progress.fadeOut( 200 );
            }, 5000 );
        }
    }

    function extractErrorMessage( xhr, status ) {
        if ( status === 'timeout' ) {
            return 'Таймаут запроса (90 сек).';
        }
        if ( xhr && xhr.responseJSON && xhr.responseJSON.data ) {
            if ( typeof xhr.responseJSON.data === 'string' ) {
                return xhr.responseJSON.data;
            }
            if ( xhr.responseJSON.data.message ) {
                return xhr.responseJSON.data.message;
            }
        }
        if ( xhr && xhr.status ) {
            return 'HTTP ' + xhr.status + '.';
        }
        return 'Ошибка сети.';
    }

    function post( action, data, onDone ) {
        var payload = $.extend( { action: action, nonce: gimData.nonce }, data || {} );

        $.ajax( {
            url: gimData.ajaxUrl,
            type: 'POST',
            data: payload,
            timeout: 90000
        } )
        .done( function ( response ) {
            if ( response && response.success ) {
                onDone( null, response.data );
            } else {
                onDone( ( response && response.data && response.data.message ) || 'Неизвестная ошибка', null );
            }
        } )
        .fail( function ( xhr, status ) {
            onDone( extractErrorMessage( xhr, status ), null );
        } );
    }

    function bulkProcess( urls, action, onProgress, onDone ) {
        var total     = urls.length;
        var index     = 0;
        var success   = 0;
        var errors    = 0;
        var skipped   = 0;
        var stopped   = false;
        var stopReason = '';

        function next() {
            if ( stopped || index >= total ) {
                onDone( {
                    total: total,
                    success: success,
                    errors: errors,
                    skipped: skipped,
                    stopped: stopped,
                    stopReason: stopReason
                } );
                return;
            }

            var url = urls[ index ];
            index++;

            post( action, { url: url }, function ( err, data ) {
                if ( err ) {
                    var lowerErr = String( err ).toLowerCase();

                    if ( lowerErr.indexOf( 'исключён' ) !== -1 ) {
                        skipped++;
                    } else {
                        errors++;
                    }

                    if ( lowerErr.indexOf( 'квота' ) !== -1
                        || lowerErr.indexOf( 'лимит' ) !== -1
                        || lowerErr.indexOf( 'исчерпан' ) !== -1 ) {
                        stopped = true;
                        stopReason = err;
                    }
                } else {
                    success++;
                }

                onProgress( index, total, url, err );

                setTimeout( next, 200 );
            } );
        }

        next();
    }

    /* ---------------------------------------------------------------------
     * Принудительная установка значения per-page после загрузки.
     *
     * Браузер может восстановить DOM из кеша, тема админки — сбросить select,
     * сторонний плагин — переопределить стили. Если текущее значение селекта
     * не совпадает с data-selected, выставляем нужное принудительно.
     * ------------------------------------------------------------------ */

    var $perPage = $( '#gim-urls-per-page' );

    if ( $perPage.length ) {
        var expected = $perPage.attr( 'data-selected' );

        if ( typeof expected !== 'undefined' && expected !== '' ) {
            if ( String( $perPage.val() ) !== String( expected ) ) {
                $perPage.val( String( expected ) );
            }
        }
    }

    /* ---------------------------------------------------------------------
     * Перетаскивание стат-карточек.
     * ------------------------------------------------------------------ */

    var $statsGrid = $( '#gim-stats-grid' );

    if ( $statsGrid.length && $.fn.sortable ) {
        $statsGrid.sortable( {
            items: '.gim-stat',
            cursor: 'grabbing',
            opacity: 0.85,
            tolerance: 'pointer',
            placeholder: 'gim-stat-placeholder',
            forcePlaceholderSize: true,
            update: function () {
                var order = $statsGrid.find( '.gim-stat' ).map( function () {
                    return $( this ).data( 'key' );
                } ).get();

                post( 'gim_save_stats_order', { order: order }, function () {} );
            }
        } );
    }

    /* ---------------------------------------------------------------------
     * Селектор тегов.
     * ------------------------------------------------------------------ */

    var $tags = $( '#gim-tags' );

    if ( $tags.length ) {
        var $field    = $( '#gim-tags-field' );
        var $input    = $( '#gim-tags-search' );
        var $dropdown = $( '#gim-tags-dropdown' );
        var $options  = $( '#gim-tags-options' );
        var $empty    = $( '#gim-tags-empty' );
        var $hidden   = $( '#gim-excluded-tags' );

        var currentResults = [];
        var activeIndex    = -1;
        var searchTimer    = null;

        var getSelectedIds = function () {
            var ids = [];
            $field.find( '.gim-tag-chip' ).each( function () {
                ids.push( parseInt( $( this ).data( 'id' ), 10 ) );
            } );
            return ids;
        };

        var updateHidden = function () {
            $hidden.val( getSelectedIds().join( ',' ) );
        };

        var updatePlaceholder = function () {
            $input.attr(
                'placeholder',
                getSelectedIds().length === 0 ? 'Начните вводить название тега…' : ''
            );
        };

        var escapeHtml = function ( text ) {
            return $( '<div>' ).text( text ).html();
        };

        var openDropdown = function () {
            $dropdown.removeAttr( 'hidden' );
        };

        var closeDropdown = function () {
            $dropdown.attr( 'hidden', true );
            activeIndex = -1;
        };

        var addTag = function ( id, name ) {
            if ( getSelectedIds().indexOf( id ) !== -1 ) {
                return;
            }
            var $chip = $( '<span class="gim-tag-chip"></span>' )
                .attr( 'data-id', id )
                .append( $( '<span class="gim-tag-chip__name"></span>' ).text( name ) )
                .append(
                    '<button type="button" class="gim-tag-chip__remove" aria-label="Удалить">' +
                    '<span class="gim-tag-chip__icon" aria-hidden="true">×</span>' +
                    '</button>'
                );
            $input.before( $chip );
            updateHidden();
            updatePlaceholder();
        };

        $field.on( 'click', function ( e ) {
            if ( ! $( e.target ).closest( '.gim-tag-chip__remove' ).length ) {
                $input.trigger( 'focus' );
            }
        } );

        $field.on( 'click', '.gim-tag-chip__remove', function ( e ) {
            e.stopPropagation();
            $( this ).closest( '.gim-tag-chip' ).remove();
            updateHidden();
            updatePlaceholder();
            $input.trigger( 'focus' );
        } );

        $input.on( 'focus', function () {
            $tags.addClass( 'is-focused' );
            if ( currentResults.length || $input.val().trim() ) {
                openDropdown();
            }
        } );

        $input.on( 'blur', function () {
            $tags.removeClass( 'is-focused' );
        } );

        $input.on( 'input', function () {
            clearTimeout( searchTimer );
            var q = $( this ).val().trim();

            searchTimer = setTimeout( function () {
                post( 'gim_search_tags', { q: q }, function ( err, data ) {
                    if ( err ) {
                        return;
                    }
                    renderResults( data.tags || [], q );
                } );
            }, 250 );
        } );

        $input.on( 'keydown', function ( e ) {
            if ( e.key === 'ArrowDown' ) {
                e.preventDefault();
                if ( $dropdown.is( ':hidden' ) && currentResults.length ) {
                    openDropdown();
                }
                moveActive( 1 );
                return;
            }
            if ( e.key === 'ArrowUp' ) {
                e.preventDefault();
                moveActive( -1 );
                return;
            }
            if ( e.key === 'Enter' ) {
                if ( activeIndex >= 0 && currentResults[ activeIndex ] ) {
                    e.preventDefault();
                    addTag( currentResults[ activeIndex ].id, currentResults[ activeIndex ].name );
                    $input.val( '' );
                    currentResults = [];
                    closeDropdown();
                }
                return;
            }
            if ( e.key === 'Escape' ) {
                closeDropdown();
                return;
            }
            if ( e.key === 'Backspace' && $input.val() === '' ) {
                var $chips = $field.find( '.gim-tag-chip' );
                if ( $chips.length ) {
                    e.preventDefault();
                    $chips.last().remove();
                    updateHidden();
                    updatePlaceholder();
                }
            }
        } );

        var moveActive = function ( delta ) {
            if ( ! currentResults.length ) {
                return;
            }
            activeIndex += delta;
            if ( activeIndex < 0 ) {
                activeIndex = currentResults.length - 1;
            }
            if ( activeIndex >= currentResults.length ) {
                activeIndex = 0;
            }
            $options.find( '.gim-tags__option' ).removeClass( 'is-active' );
            $options.find( '.gim-tags__option' ).eq( activeIndex ).addClass( 'is-active' );

            var $active = $options.find( '.gim-tags__option' ).eq( activeIndex );
            if ( $active.length ) {
                var top = $active.position().top;
                var h   = $active.outerHeight();
                var st  = $dropdown.scrollTop();
                var dh  = $dropdown.height();
                if ( top < 0 ) {
                    $dropdown.scrollTop( st + top );
                } else if ( top + h > dh ) {
                    $dropdown.scrollTop( st + top + h - dh );
                }
            }
        };

        var renderResults = function ( tags, q ) {
            var selected = getSelectedIds();
            currentResults = [];
            activeIndex    = -1;
            $options.empty();

            tags.forEach( function ( tag ) {
                if ( selected.indexOf( tag.id ) === -1 ) {
                    currentResults.push( tag );
                }
            } );

            if ( ! currentResults.length ) {
                $empty.attr( 'hidden', false );
                $empty.text( q ? 'Ничего не найдено' : 'Все подходящие теги уже добавлены' );
                openDropdown();
                return;
            }

            $empty.attr( 'hidden', true );

            currentResults.forEach( function ( tag, idx ) {
                var $item = $( '<div class="gim-tags__option" role="option"></div>' )
                    .attr( 'data-id', tag.id )
                    .attr( 'data-name', tag.name )
                    .attr( 'data-index', idx )
                    .append(
                        '<span class="gim-tags__option-name">' + escapeHtml( tag.name ) + '</span>' +
                        '<span class="gim-tags__option-count">' + tag.count + '</span>'
                    );
                $options.append( $item );
            } );

            openDropdown();
        };

        $options.on( 'click', '.gim-tags__option', function () {
            var id   = parseInt( $( this ).data( 'id' ), 10 );
            var name = $( this ).data( 'name' );
            addTag( id, name );
            $input.val( '' );
            currentResults = [];
            closeDropdown();
            $input.trigger( 'focus' );
        } );

        $( document ).on( 'click', function ( e ) {
            if ( ! $( e.target ).closest( '#gim-tags' ).length ) {
                closeDropdown();
            }
        } );

        updatePlaceholder();
    }

    /* ---------------------------------------------------------------------
     * Per-page селектор на вкладке URL.
     * ------------------------------------------------------------------ */

    $( document ).on( 'change', '#gim-urls-per-page', function () {
        var value = $( this ).val();
        if ( ! value ) {
            return;
        }

        try {
            var url = new URL( window.location.href );
            url.searchParams.set( 'per_page', value );
            url.searchParams.delete( 'paged' );
            window.location.href = url.toString();
        } catch ( e ) {
            var href = window.location.href
                .replace( /([?&])per_page=[^&]*&?/g, '$1' )
                .replace( /([?&])paged=[^&]*&?/g, '$1' )
                .replace( /[?&]$/, '' );
            var sep = href.indexOf( '?' ) !== -1 ? '&' : '?';
            window.location.href = href + sep + 'per_page=' + encodeURIComponent( value );
        }
    } );

    /* ---------------------------------------------------------------------
     * Массовые действия на вкладке URL.
     * ------------------------------------------------------------------ */

    var $bulkBar      = $( '#gim-bulk-bar' );
    var $bulkProgress = $( '#gim-bulk-progress' );
    var $bulkCheck    = $( '#gim-bulk-check' );
    var $bulkSend     = $( '#gim-bulk-send' );

    function getSelectedUrls() {
        var urls = [];
        $( '.gim-row-checkbox:checked' ).each( function () {
            urls.push( $( this ).val() );
        } );
        return urls;
    }

    function updateBulkBar() {
        if ( ! $bulkBar.length ) {
            return;
        }
        var count = $( '.gim-row-checkbox:checked' ).length;

        $( '#gim-bulk-count' ).text( count );

        if ( count > 0 ) {
            $bulkBar.css( 'display', 'flex' );
        } else {
            $bulkBar.css( 'display', 'none' );
            $bulkProgress.text( '' );
        }
    }

    function clearSelection() {
        $( '.gim-row-checkbox' )
            .prop( 'checked', false )
            .each( function () {
                $( this ).closest( 'tr' ).removeClass( 'is-selected' );
            } );

        $( '#gim-select-all' )
            .prop( 'checked', false )
            .prop( 'indeterminate', false );

        if ( $bulkBar.length ) {
            $bulkBar.removeClass( 'is-processing' ).css( 'display', 'none' );
        }
        if ( $bulkProgress.length ) {
            $bulkProgress.text( '' );
        }
        if ( $bulkCheck.length ) {
            $bulkCheck.prop( 'disabled', false ).text( 'Проверить выбранные' );
        }
        if ( $bulkSend.length ) {
            $bulkSend.prop( 'disabled', false ).text( 'Отправить выбранные' );
        }
    }

    function reloadClean() {
        clearSelection();

        setTimeout( function () {
            window.location.href = window.location.href;
        }, 50 );
    }

    function setBulkProcessing( processing ) {
        $bulkCheck.prop( 'disabled', processing );
        $bulkSend.prop( 'disabled', processing );
        $( '#gim-bulk-clear' ).prop( 'disabled', processing );
        $bulkBar.toggleClass( 'is-processing', processing );
    }

    $( window ).on( 'pageshow', function ( event ) {
        if ( event.originalEvent && event.originalEvent.persisted ) {
            clearSelection();
        }
    } );

    $( document ).on( 'change', '#gim-select-all', function () {
        var checked = $( this ).is( ':checked' );
        $( '.gim-row-checkbox' )
            .prop( 'checked', checked )
            .each( function () {
                $( this ).closest( 'tr' ).toggleClass( 'is-selected', checked );
            } );
        updateBulkBar();
    } );

    $( document ).on( 'change', '.gim-row-checkbox', function () {
        $( this ).closest( 'tr' ).toggleClass( 'is-selected', $( this ).is( ':checked' ) );

        var total   = $( '.gim-row-checkbox' ).length;
        var checked = $( '.gim-row-checkbox:checked' ).length;
        var $selectAll = $( '#gim-select-all' );

        $selectAll.prop( 'checked', total > 0 && checked === total );
        $selectAll.prop( 'indeterminate', checked > 0 && checked < total );

        updateBulkBar();
    } );

    $( '#gim-bulk-clear' ).on( 'click', function () {
        clearSelection();
    } );

    $( '#gim-bulk-check' ).on( 'click', function () {
        var urls = getSelectedUrls();
        if ( ! urls.length ) {
            return;
        }

        setBulkProcessing( true );
        $bulkProgress.text( 'Проверка: 0 / ' + urls.length );

        bulkProcess(
            urls,
            'gim_check_url',
            function ( processed, total, url, err ) {
                $bulkProgress.text( 'Проверка: ' + processed + ' / ' + total );
            },
            function ( result ) {
                var msg = 'Готово: успешно ' + result.success + ', ошибок ' + result.errors;

                if ( result.skipped > 0 ) {
                    msg += ', пропущено ' + result.skipped;
                }
                if ( result.stopped ) {
                    msg += '. Остановлено: ' + result.stopReason;
                }

                $bulkProgress.text( msg );

                setTimeout( reloadClean, 1500 );
            }
        );
    } );

    $( '#gim-bulk-send' ).on( 'click', function () {
        var urls = getSelectedUrls();
        if ( ! urls.length ) {
            return;
        }

        setBulkProcessing( true );
        $bulkProgress.text( 'Отправка: 0 / ' + urls.length );

        bulkProcess(
            urls,
            'gim_send_url',
            function ( processed, total, url, err ) {
                $bulkProgress.text( 'Отправка: ' + processed + ' / ' + total );
            },
            function ( result ) {
                var msg = 'Готово: успешно ' + result.success + ', ошибок ' + result.errors;

                if ( result.skipped > 0 ) {
                    msg += ', пропущено ' + result.skipped;
                }
                if ( result.stopped ) {
                    msg += '. Остановлено: ' + result.stopReason;
                }

                $bulkProgress.text( msg );

                setTimeout( reloadClean, 1500 );
            }
        );
    } );

    /* ---------------------------------------------------------------------
     * Кнопки на странице статистики.
     * ------------------------------------------------------------------ */

    $( '#gim-sync' ).on( 'click', function () {
        var $btn = $( this );
        $btn.prop( 'disabled', true ).text( 'Синхронизация...' );

        post( 'gim_sync', {}, function ( err, data ) {
            if ( err ) {
                showMessage( 'Ошибка: ' + err, 'error', true );
            } else {
                showMessage( data.message, 'success', false );
                reloadClean();
            }
            $btn.prop( 'disabled', false ).text( 'Синхронизировать URL' );
        } );
    } );

    $( '#gim-run-check-now' ).on( 'click', function () {
        var $btn = $( this );
        $btn.prop( 'disabled', true ).text( 'Проверка...' );

        post( 'gim_run_check_now', {}, function ( err, data ) {
            if ( err ) {
                showMessage( 'Ошибка: ' + err, 'error', true );
            } else {
                showMessage( data.message, 'success', false );
                reloadClean();
            }
            $btn.prop( 'disabled', false );
        } );
    } );

    $( '#gim-run-send-now' ).on( 'click', function () {
        var $btn = $( this );
        $btn.prop( 'disabled', true ).text( 'Отправка...' );

        post( 'gim_run_send_now', {}, function ( err, data ) {
            if ( err ) {
                showMessage( 'Ошибка: ' + err, 'error', true );
            } else {
                showMessage( data.message, 'success', false );
                reloadClean();
            }
            $btn.prop( 'disabled', false );
        } );
    } );

    $( document ).on( 'click', '.gim-check-url', function () {
        var $btn = $( this );
        var url  = $btn.data( 'url' );
        $btn.prop( 'disabled', true ).text( 'Проверка...' );

        post( 'gim_check_url', { url: url }, function ( err ) {
            if ( err ) {
                showMessage( 'Ошибка: ' + err, 'error', true );
                $btn.prop( 'disabled', false ).text( 'Проверить' );
            } else {
                reloadClean();
            }
        } );
    } );

    $( document ).on( 'click', '.gim-send-url', function () {
        var $btn = $( this );
        var url  = $btn.data( 'url' );
        $btn.prop( 'disabled', true ).text( 'Отправка...' );

        post( 'gim_send_url', { url: url }, function ( err ) {
            if ( err ) {
                showMessage( 'Ошибка: ' + err, 'error', true );
                $btn.prop( 'disabled', false ).text( 'Отправить' );
            } else {
                reloadClean();
            }
        } );
    } );

    $( '#gim-clear-urls' ).on( 'click', function () {
        if ( ! confirm( 'Удалить все URL из таблицы? Действие необратимо.' ) ) {
            return;
        }
        var $btn = $( this );
        $btn.prop( 'disabled', true ).text( 'Очистка...' );

        post( 'gim_clear_urls', {}, function ( err, data ) {
            if ( err ) {
                showMessage( 'Ошибка: ' + err, 'error', true );
                $btn.prop( 'disabled', false ).text( 'Сбросить таблицу' );
            } else {
                showMessage( data.message, 'success', false );
                reloadClean();
            }
        } );
    } );

    $( '#gim-clear-token' ).on( 'click', function () {
        var $btn = $( this );
        $btn.prop( 'disabled', true ).text( 'Сброс...' );

        post( 'gim_clear_token', {}, function ( err, data ) {
            if ( err ) {
                showMessage( 'Ошибка: ' + err, 'error', true );
            } else {
                showMessage( data.message, 'success', false );
            }
            $btn.prop( 'disabled', false ).text( 'Сбросить access token' );
        } );
    } );

    $( '#gim-reset-statuses' ).on( 'click', function () {
        if ( ! confirm( 'Сбросить все статусы проверок?' ) ) {
            return;
        }
        var $btn = $( this );
        $btn.prop( 'disabled', true ).text( 'Сброс...' );

        post( 'gim_reset_statuses', {}, function ( err, data ) {
            if ( err ) {
                showMessage( 'Ошибка: ' + err, 'error', true );
                $btn.prop( 'disabled', false ).text( 'Сбросить статусы проверок' );
            } else {
                showMessage( data.message, 'success', false );
                reloadClean();
            }
        } );
    } );

    $( '#gim-recompute-exclusions' ).on( 'click', function () {
        var $btn = $( this );
        $btn.prop( 'disabled', true ).text( 'Пересчёт...' );

        post( 'gim_recompute_exclusions', {}, function ( err, data ) {
            if ( err ) {
                showMessage( 'Ошибка: ' + err, 'error', true );
                $btn.prop( 'disabled', false ).text( 'Пересчитать исключения сейчас' );
            } else {
                showMessage( data.message, 'success', false );
                reloadClean();
            }
        } );
    } );

    $( '#gim-purge-logs' ).on( 'click', function () {
        if ( ! confirm( 'Удалить все записи логов старше указанного срока хранения? Действие необратимо.' ) ) {
            return;
        }
        var $btn = $( this );
        $btn.prop( 'disabled', true ).text( 'Очистка...' );

        post( 'gim_purge_logs', {}, function ( err, data ) {
            if ( err ) {
                showMessage( 'Ошибка: ' + err, 'error', true );
                $btn.prop( 'disabled', false ).text( 'Очистить логи сейчас' );
            } else {
                showMessage( data.message, 'success', false );
                reloadClean();
            }
        } );
    } );

    /* ---------------------------------------------------------------------
     * Восстановление статусов из резервной копии (вкладка «Диагностика»).
     * ------------------------------------------------------------------ */

    $( document ).on( 'click', '.gim-restore-backup', function () {
        if ( ! confirm( 'Восстановить статусы URL из этой резервной копии? Более свежие проверки не перезаписываются.' ) ) {
            return;
        }

        var $btn   = $( this );
        var backup = $btn.data( 'backup' );

        $btn.prop( 'disabled', true ).text( 'Восстановление...' );

        post( 'gim_restore_backup', { backup: backup }, function ( err, data ) {
            if ( err ) {
                alert( 'Ошибка: ' + err );
                $btn.prop( 'disabled', false ).text( 'Восстановить' );
                return;
            }

            alert( data.message );
            window.location.reload();
        } );
    } );

    /* ---------------------------------------------------------------------
     * Диагностика.
     * ------------------------------------------------------------------ */

    $( '#gim-run-diagnostic' ).on( 'click', function () {
        var $btn = $( this );
        $btn.prop( 'disabled', true ).text( 'Диагностика...' );

        var $rows = $( '.gim-diagnostic-table' ).find( 'tr[data-step]' );

        $rows.each( function () {
            $( this ).find( '[data-role="status"]' )
                .attr( 'class', 'gim-badge gim-badge-pending' )
                .text( '…' );
            $( this ).find( '[data-role="message"]' )
                .html( '<span class="gim-worker-value">Выполняется…</span>' );
        } );

        $( '#gim-diagnostic-summary' ).attr( 'hidden', true ).empty();

        // A real Indexing API probe spends one daily notification: it runs only
        // when the administrator ticks the box.
        var probe = $( '#gim-diagnostic-probe' ).is( ':checked' ) ? '1' : '0';

        post( 'gim_diagnostic', { probe: probe }, function ( err, data ) {
            $btn.prop( 'disabled', false ).text( 'Запустить диагностику' );

            if ( err ) {
                alert( 'Ошибка: ' + err );
                return;
            }

            $.each( data.results, function ( key, result ) {
                var $row = $rows.filter( '[data-step="' + key + '"]' );
                if ( ! $row.length ) {
                    return;
                }
                $row.find( '[data-role="status"]' )
                    .attr( 'class', 'gim-badge ' + result.status_class )
                    .text( result.status_label );
                $row.find( '[data-role="message"]' ).html( result.message );
            } );

            $rows.each( function () {
                var step = $( this ).data( 'step' );
                if ( typeof data.results[ step ] === 'undefined' ) {
                    $( this ).find( '[data-role="status"]' )
                        .attr( 'class', 'gim-badge gim-badge-pending' )
                        .text( '—' );
                    $( this ).find( '[data-role="message"]' )
                        .html( '<span class="gim-worker-value">Пропущено из-за предыдущей ошибки.</span>' );
                }
            } );

            var $summary = $( '#gim-diagnostic-summary' );
            $summary.attr( 'hidden', false );

            if ( data.has_error ) {
                $summary.html( '<div class="notice notice-error inline"><p>' + data.summary + '</p></div>' );
            } else if ( data.has_warning ) {
                $summary.html( '<div class="notice notice-warning inline"><p>' + data.summary + '</p></div>' );
            } else {
                $summary.html( '<div class="notice notice-success inline"><p>' + data.summary + '</p></div>' );
            }
        } );
    } );
} );