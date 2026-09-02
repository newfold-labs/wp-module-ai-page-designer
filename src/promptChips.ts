// Each pool below deliberately mixes two voices: "technical" chips use the
// terms this app's own UI/output uses (hero section, CTA, social proof) for
// users who already think in those terms, and "layman" chips describe the
// exact same idea in plain, everyday language for users who don't. Keeping
// both in the same pool means a random pick of 3 usually surfaces one of
// each, rather than an all-jargon or all-vague set.

export const DASHBOARD_PROMPT_CHIPS = [
  'Create a homepage with a hero section, key benefits, and a clear CTA.', // technical
  'Build a simple homepage that welcomes visitors and explains what we offer.', // layman
  'Design a conversion-focused landing page with social proof and a strong CTA.', // technical
  'Make a pricing page that is easy to compare and simple to choose from.', // layman
  'Build a page that introduces our brand story and ends with a strong call to action.', // technical
  'Create a friendly homepage that feels warm and easy to trust.', // layman
];

export const CHAT_SELECTED_BLOCK_PROMPT_CHIPS = [
  'Make this section feel more polished and professional while keeping the same message.', // technical
  'Make this part sound friendlier and easier to read.', // layman
  'Add a testimonials section right after this so it builds trust with real customer stories.', // technical
  'Add a few customer reviews right below this.', // layman
  'Turn this into a clear pricing table with three options and a strong recommendation.', // technical
  'Turn this into a simple table comparing our plans.', // layman
];

export const CHAT_NEW_PAGE_PROMPT_CHIPS = [
  'Create a homepage with a hero section, key features, and a call to action.', // technical
  'Build a homepage that explains what we do in plain, friendly language.', // layman
  'Add a pricing table with three tiers, clear differences, and a clear CTA.', // technical
  'Make a page that shows off customer reviews and builds trust.', // layman
  'Design a high-converting page with strong structure, social proof, and a clear CTA section.', // technical
  'Create a simple page that welcomes visitors and tells them what to do next.', // layman
];

export const CHAT_EXISTING_PAGE_PROMPT_CHIPS = (
  selectedTitle: string
): string[] => [
  `Refresh "${ selectedTitle }" so it sounds more confident, modern, and customer-friendly.`, // technical
  'Add a few real customer reviews to this page.', // layman
  'Add a pricing table that is easy to compare and choose from the best option.', // technical
  'Add a short summary at the top so people know what this page is about.', // layman
  'Add an excerpt to the page so it is SEO friendly.', // technical
  'Make this page simpler and easier to read.', // layman
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
