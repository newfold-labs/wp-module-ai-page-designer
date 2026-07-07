// @wordpress/interface ships no build-types (unlike @wordpress/components and
// @wordpress/data) — this covers only the InterfaceSkeleton props this app uses.
declare module '@wordpress/interface' {
  import type { ReactNode } from 'react';

  type InterfaceSkeletonProps = {
    header?: ReactNode;
    secondarySidebar?: ReactNode;
    sidebar?: ReactNode;
    content?: ReactNode;
    actions?: ReactNode;
    footer?: ReactNode;
    className?: string;
  };

  export const InterfaceSkeleton: ( props: InterfaceSkeletonProps ) => JSX.Element;
}
