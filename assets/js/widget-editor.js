;(function (blocks, blockEditor, components, element, i18n) {
  'use strict'

  var createElement = element.createElement
  var settings = window.WebVouchWooWidgets || { widgets: {}, managerUrl: '' }
  var options = [
    {
      label: i18n.__('Reviews carousel', 'webvouch-for-woocommerce'),
      value: 'carousel',
    },
    {
      label: i18n.__('Rating badge', 'webvouch-for-woocommerce'),
      value: 'badge',
    },
    {
      label: i18n.__('Text badge', 'webvouch-for-woocommerce'),
      value: 'text-badge',
    },
    {
      label: i18n.__('Text and stars', 'webvouch-for-woocommerce'),
      value: 'text-combo',
    },
  ]

  function Edit(props) {
    var type = props.attributes.widgetType || 'text-badge'
    var widget = settings.widgets[type] || {}
    var status = widget.locked
      ? i18n.__(
          'This widget requires a higher WebVouch plan.',
          'webvouch-for-woocommerce',
        )
      : widget.ready
        ? i18n.__('Ready on the storefront.', 'webvouch-for-woocommerce')
        : i18n.__(
            'Activate and sync this widget under WooCommerce > WebVouch > Widgets.',
            'webvouch-for-woocommerce',
          )
    var blockProps = blockEditor.useBlockProps({
      className: 'webvouch-widget-editor-placeholder',
    })

    return createElement(
      'div',
      blockProps,
      createElement(
        blockEditor.InspectorControls,
        null,
        createElement(
          components.PanelBody,
          {
            title: i18n.__('WebVouch widget', 'webvouch-for-woocommerce'),
            initialOpen: true,
          },
          createElement(components.SelectControl, {
            label: i18n.__('Widget type', 'webvouch-for-woocommerce'),
            value: type,
            options: options,
            onChange: function (next) {
              props.setAttributes({ widgetType: next })
            },
          }),
        ),
      ),
      createElement(
        components.Placeholder,
        {
          icon: 'star-filled',
          label:
            widget.label ||
            i18n.__('WebVouch widget', 'webvouch-for-woocommerce'),
        },
        createElement('p', null, status),
        settings.managerUrl
          ? createElement(
              components.Button,
              { variant: 'secondary', href: settings.managerUrl },
              i18n.__('Manage WebVouch widgets', 'webvouch-for-woocommerce'),
            )
          : null,
      ),
    )
  }

  blocks.registerBlockType('webvouch/widget', {
    edit: Edit,
    save: function () {
      return null
    },
  })

  options.forEach(function (option) {
    blocks.registerBlockVariation('webvouch/widget', {
      name: option.value,
      title: 'WebVouch: ' + option.label,
      attributes: { widgetType: option.value },
      isActive: ['widgetType'],
      scope: ['inserter', 'transform'],
    })
  })
})(
  window.wp.blocks,
  window.wp.blockEditor,
  window.wp.components,
  window.wp.element,
  window.wp.i18n,
)
