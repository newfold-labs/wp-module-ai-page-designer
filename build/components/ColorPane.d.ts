import React from 'react';
type ColorSwatch = {
    slug: string;
    name: string;
    color: string;
};
type Props = {
    colorPalette: ColorSwatch[];
    previewHtml: string | null;
    onApplyDirectChange: (html: string, label: string) => void;
};
declare const ColorPane: ({ colorPalette, previewHtml, onApplyDirectChange }: Props) => React.JSX.Element;
export default ColorPane;
//# sourceMappingURL=ColorPane.d.ts.map