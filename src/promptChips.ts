export const DASHBOARD_PROMPT_CHIPS = [
  'Create a homepage that feels warm and trustworthy, with a strong hero and clear next step.',
  'Build a pricing table with three plans that are easy to compare and simple to choose from.',
  'Design a clean services page that feels premium and makes it obvious what to do next.',
  'Create a landing page that feels modern, friendly, and focused on one clear offer.',
  'Build a page that introduces our brand story and ends with a strong call to action.',
  'Create a conversion-focused homepage with social proof, benefits, and a clear next step.',
];

export const CHAT_SELECTED_BLOCK_PROMPT_CHIPS = [
  'Make this section feel more polished and professional while keeping the same message.',
  'Add a testimonials section right after this so it builds trust with real customer stories.',
  'Turn this into a clear pricing table with three options and a strong recommendation.',
  'Rewrite this section so it sounds more confident, clear, and benefit-focused.',
  'Make this part easier to scan with tighter copy and stronger headings.',
  'Keep the same idea, but make this section feel more premium and persuasive.',
];

export const CHAT_NEW_PAGE_PROMPT_CHIPS = [
  'Create a homepage that feels warm and trustworthy, with a clear story from top to bottom.',
  'Add a testimonials section that sounds authentic and highlights real outcomes.',
  'Add a pricing table with three tiers, clear differences, and a confident call to action.',
  'Build a modern homepage that starts bold, explains value quickly, and drives action.',
  'Create a page that feels human and approachable, with clear benefits in every section.',
  'Design a high-converting page with strong structure, social proof, and a clear final CTA.',
];

export const CHAT_EXISTING_PAGE_PROMPT_CHIPS = (
  selectedTitle: string
): string[] => [
  `Refresh "${ selectedTitle }" so it sounds more confident, modern, and customer-friendly.`,
  'Add a testimonials section that feels genuine and supports the main offer.',
  'Add a pricing table that is easy to compare and nudges people toward the best plan.',
  'Tighten the copy across this page so it feels clearer, sharper, and more persuasive.',
  'Keep the topic the same, but make the page flow feel more intentional and conversion-focused.',
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
