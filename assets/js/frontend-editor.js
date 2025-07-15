jQuery(function ($) {
  'use strict'

  console.log('[DTE] Front-end script initialised')

  // Ensure DTE object exists from wp_localize_script.
  if (typeof DTE === 'undefined') {
    console.warn('[DTE] DTE data not found. Script will abort.')
    return
  }

  const $selector = $('#dte-layout-selector')
  const $editorsWrap = $('#dte-text-editors')
  const $saveButton = $('#dte-save-button')
  const $message = $('#dte-message')

  function showMessage (text, isError = false) {
    $message.text(text).css('color', isError ? 'red' : 'green')
  }

  function resetEditor () {
    $editorsWrap.empty()
    $saveButton.hide().prop('disabled', true)
    $message.empty()
  }

  // Fetch content when a layout is selected.
  $selector.on('change', function () {
    const layoutId = $(this).val()

    console.log('[DTE] Selected layout:', layoutId)

    resetEditor()

    if (!layoutId) {
      return
    }

    $.post(
      DTE.ajax_url,
      {
        action: 'dte_fetch_layout',
        layout_id: layoutId,
        nonce: DTE.nonce
      },
      function (response) {
        console.log('[DTE] Fetch response:', response)
        if (response.data && response.data.debug) {
          console.group('[DTE] Debug info – fetch')
          console.log(response.data.debug)
          console.groupEnd()
        }
        if (response.success) {
          const texts = response.data.texts
          window.DTE_META = response.data.meta || []

          // Verbose log of all captured texts (ordered).
          console.group('[DTE] Text list (' + texts.length + ' items)')
          texts.forEach(function (txt, idx) {
            console.log(idx + ':', txt)
          })
          console.groupEnd()

          texts.forEach(function (text, index) {
            const textarea = $(
              '<div style="margin-bottom:12px;">' +
                '<label style="display:block;margin-bottom:4px;">Block ' +
                (index + 1) +
                '</label>' +
                '<textarea data-index="' +
                index +
                '" style="width:100%;min-height:120px;">' +
                text.replace(/</g, '&lt;').replace(/>/g, '&gt;') +
                '</textarea>' +
                '</div>'
            )
            $editorsWrap.append(textarea)
          })

          if (texts.length) {
            $saveButton.show().prop('disabled', false)
          }
        } else {
          showMessage(response.data || 'Failed to fetch layout.', true)
        }
      },
      'json'
    )
  })

  // Save changes.
  $saveButton.on('click', function () {
    const layoutId = $selector.val()

    console.log('[DTE] Saving layout:', layoutId)

    if (!layoutId) {
      showMessage('No layout selected.', true)
      return
    }

    const texts = $editorsWrap
      .find('textarea')
      .map(function () {
        return $(this).val()
      })
      .get()

    console.log('[DTE] Texts to save:', texts)

    $saveButton.prop('disabled', true)
    showMessage('Saving…')

    $.post(
      DTE.ajax_url,
      {
        action: 'dte_save_layout',
        layout_id: layoutId,
        texts: texts,
        meta: JSON.stringify(window.DTE_META || []),
        nonce: DTE.nonce
      },
      function (response) {
        console.log('[DTE] Save response:', response)
        if (response.data && response.data.debug) {
          console.group('[DTE] Debug info – save')
          console.log(response.data.debug)
          console.groupEnd()
        }
        if (response.success) {
          showMessage('Changes saved successfully.')
        } else {
          showMessage(response.data || 'Save failed.', true)
        }
        $saveButton.prop('disabled', false)
      },
      'json'
    )
  })
})
