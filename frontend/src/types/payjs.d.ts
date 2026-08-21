// Ambient types for Google Pay (pay.google.com/gp/p/ui/pay.js). Google ships no
// official TypeScript types; this is the minimal surface GooglePayButton uses
// (PaymentsClient, createButton, isReadyToPay, loadPaymentData).
// Reference: https://developers.google.com/pay/api/web/reference/client

// Ambient types for Google Identity Services (accounts.google.com/gsi/client) —
// the client-side "Sign in with Google" button used by GoogleSignInButton.
// Reference: https://developers.google.com/identity/gsi/web/reference/js-reference

interface Window {
  google?: {
    payments?: {
      api?: {
        PaymentsClient: new (options?: {
          environment?: "TEST" | "PRODUCTION";
        }) => google.payments.api.PaymentsClient;
      };
    };
    accounts?: {
      id: {
        initialize(config: {
          client_id: string;
          callback: (response: { credential: string }) => void;
          error_callback?: (error: { type?: string; message?: string }) => void;
        }): void;
        renderButton(
          parent: HTMLElement,
          options?: {
            theme?: "outline" | "filled_blue" | "filled_black";
            size?: "large" | "medium" | "small";
            width?: number;
            shape?: "rectangular" | "pill";
            text?: "signin_with" | "signup_with" | "continue_with" | "signin";
            locale?: string;
            logo_alignment?: "left" | "center";
          }
        ): void;
        prompt(): void;
        disableAutoSelect(): void;
        revoke(googleId: string, callback?: (response: unknown) => void): void;
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