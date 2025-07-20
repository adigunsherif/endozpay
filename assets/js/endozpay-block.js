( function () {
  const { createElement } = window.wp.element;
  const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
  const logoUrl = window.endozpay_data?.logo_url || '';

  const options = {
    name: 'endozpay',
    title: 'Endozpay',
    description: 'Pay securely using EndozPay via OpenBanking',
    ariaLabel: 'EndozPay - Pay securely using OpenBanking',
    gatewayId: 'endozpay',
    label: createElement(
      'div',
      { style: { display: 'flex', alignItems: 'center', gap: '10px' } },
      logoUrl && createElement('img', {
        src: logoUrl,
        alt: 'Pay with Endoz',
        style: { height: '32px', width: 'auto' }
      }),
      createElement('span', {}, '')
    ),
    //label: createElement('div', {}, 'EndozPay'),
    content: createElement('div', {}, 'You will be redirected to Endoz to complete your payment.'),
    edit: createElement('div', {}, 'You will be redirected to Endoz to complete your payment.'),
    canMakePayment: () => true,
    paymentMethodId: 'endozpay',
    supports: {
      features: ['products'],
      style: [],
    },
  };

  registerPaymentMethod(options);
} )();
