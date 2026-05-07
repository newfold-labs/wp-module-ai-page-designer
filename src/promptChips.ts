export const DASHBOARD_PROMPT_CHIPS = [
  'Create a homepage that feels warm and trustworthy, with a hero section and a CTA section.',
  'Build a pricing table with three plans that are easy to compare and simple to choose from.',
  'Create a landing page that feels modern, friendly, and focused.',
  'Build a page that introduces our brand story and ends with a strong call to action.',
  'Create a conversion-focused homepage with social proof, benefits, and a clear CTA.',
];

export const CHAT_SELECTED_BLOCK_PROMPT_CHIPS = [
  'Make this section feel more polished and professional while keeping the same message.',
  'Add a testimonials section right after this so it builds trust with real customer stories.',
  'Turn this into a clear pricing table with three options and a strong recommendation.',
  'Rewrite this section so it sounds more confident, clear, and benefit-focused.',
  'Keep the same idea, but make this section feel more premium and persuasive.',
];

export const CHAT_NEW_PAGE_PROMPT_CHIPS = [
  'Create a homepage that feels warm and trustworthy, with a hero section and a CTA section.',
  'Add a testimonials section that sounds authentic and highlights real outcomes.',
  'Add a pricing table with three tiers, clear differences, and a clear CTA.',
  'Build a modern homepage that starts bold, explains value quickly, and drives action.',
  'Create a page that feels human and approachable, with clear benefits in every section.',
  'Design a high-converting page with strong structure, social proof, and a clear CTA section.',
];

export const CHAT_EXISTING_PAGE_PROMPT_CHIPS = (
  selectedTitle: string
): string[] => [
  `Refresh "${ selectedTitle }" so it sounds more confident, modern, and customer-friendly.`,
  'Add a testimonials section that feels genuine and supports the main offer.',
  'Add a pricing table that is easy to compare and choose from the best option.',
  'Keep the topic the same with a clear CTA section.',
  'Add an excerpt to the page so it is SEO friendly.',
  'Update this page so it feels more premium while staying simple and easy to read.',
];

export const pickRandomPromptChips = ( prompts: string[], count: number ): string[] => {
  if ( count <= 0 ) {
    return [];
  }
  if ( prompts.length <= count ) {
    return [ ...prompts ];
  }

  const shuffled = [ ...prompts ];
  for ( let i = shuffled.length - 1; i > 0; i-- ) {
    const j = Math.floor( Math.random() * ( i + 1 ) );
    [ shuffled[ i ], shuffled[ j ] ] = [ shuffled[ j ], shuffled[ i ] ];
  }

  return shuffled.slice( 0, count );
};
