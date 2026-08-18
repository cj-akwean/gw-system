// Ambient types for Google Pay (pay.google.com/gp/p/ui/pay.js). Google ships no
// official TypeScript types; this is the minimal surface GooglePayButton uses
// (PaymentsClient, createButton, isReadyToPay, loadPaymentData).
// Reference: https://developers.google.com/pay/api/web/reference/client

interface Window {
  google?: {
    payments?: {
      api?: {
        PaymentsClient: new (options?: {
          environment?: "TEST" | "PRODUCTION";
        }) => google.payments.api.PaymentsClient;
      };
    };
  };
}

declare namespace google.payments.api {
  interface PaymentMethod {
    type: string;
    parameters: Record<string, unknown>;
    tokenizationSpecification?: {
      type: string;
      parameters: Record<string, unknown>;
    };
  }

  interface IsReadyToPayRequest {
    apiVersion: number;
    apiVersionMinor: number;
    allowedPaymentMethods: PaymentMethod[];
  }

  interface IsReadyToPayResponse {
    resultType: string;
    paymentMethodPresent?: boolean;
  }

  interface PaymentDataRequest {
    apiVersion: number;
    apiVersionMinor: number;
    allowedPaymentMethods: PaymentMethod[];
    merchantInfo: {
      merchantId?: string;
      merchantName: string;
    };
    transactionInfo: {
      totalPriceStatus: string;
      totalPrice: string;
      currencyCode: string;
      countryCode: string;
    };
  }

  interface PaymentData {
    paymentMethodData: {
      tokenizationData: {
        type: string;
        token: string;
      };
      info: {
        cardDetails: string;
        cardNetwork: string;
        email?: string;
        billingAddress?: {
          name?: string;
          address1?: string;
          address2?: string;
          locality?: string;
          administrativeArea?: string;
          postalCode?: string;
          countryCode?: string;
        };
      };
    };
  }

  interface PaymentsClient {
    isReadyToPay(request: IsReadyToPayRequest): Promise<IsReadyToPayResponse>;
    createButton(options: {
      onClick: () => void;
      buttonType?: string;
      buttonColor?: string;
    }): HTMLElement;
    loadPaymentData(request: PaymentDataRequest): Promise<PaymentData>;
  }
}