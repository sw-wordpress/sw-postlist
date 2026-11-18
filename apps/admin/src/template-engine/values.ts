/** Typed values exchanged with the host (mirrors TrustedHtml, ListItem and ImageValue in PHP). */
export class TrustedHtml {
  constructor(public readonly html: string) {}
}

export class ListItem {
  constructor(
    public readonly label: string,
    public readonly url: string | null = null,
  ) {}
}

export class ImageValue {
  constructor(
    public readonly url: string,
    public readonly alt: string = '',
    public readonly attachmentId: number = 0,
  ) {}
}

export interface Escaper {
  text: (value: string) => string;
  attribute: (value: string) => string;
  url: (value: string) => string | null;
  color: (value: string) => string | null;
}
