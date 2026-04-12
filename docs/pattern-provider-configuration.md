# Pattern Provider Configuration

## Overview
Add a configurable flag to switch between Blueprint and WonderBlocks layout providers for testing both approaches from the same codebase.

## Current Architecture Analysis

The system currently has two layout providers:
- **BlueprintService**: Fetches random blueprints from Hiive API 
- **PatternLayoutProvider**: Uses WonderBlocks patterns with intent-based selection

Current logic in [`PromptBuilder.php`](../includes/Services/PromptBuilder.php) (lines 187-191):
```php
$base_layout = $this->blueprint_service->get_base_layout();
if ( empty( $base_layout ) ) {
    $base_layout = $this->pattern_layout_provider->get_random_pattern_layout( $content );
}
```

## Implementation Plan

### 1. Add Configuration Constant

**File**: [`AIPageDesigner.php`](../includes/AIPageDesigner.php)
- Add a class constant `PATTERN_PROVIDER` (string)
- Support values: `'wonderblocks'`, `'blueprints'`, `''` (empty for pure AI)
- Set to `'wonderblocks'` by default (WonderBlocks preferred)
- Add documentation explaining all three approaches

### 2. Update PromptBuilder Logic

**File**: [`PromptBuilder.php`](../includes/Services/PromptBuilder.php)

**Current logic** (lines 187-191):
- Try BlueprintService first
- Fall back to PatternLayoutProvider

**New logic**:
- Check the `PATTERN_PROVIDER` constant
- If `PATTERN_PROVIDER = 'wonderblocks'`: Use PatternLayoutProvider (intent-based)
- If `PATTERN_PROVIDER = 'blueprints'`: Use BlueprintService (random)
- If `PATTERN_PROVIDER = ''` (empty): Skip layout providers, let AI generate from scratch
- Remove the fallback logic to ensure pure testing of each approach

### 3. Add Helper Method

**File**: [`PromptBuilder.php`](../includes/Services/PromptBuilder.php)
- Add `get_base_layout_by_provider()` method
- Encapsulate the provider selection logic with switch statement
- Handle all three cases: wonderblocks, blueprints, and empty (pure AI)
- Add logging/comments to indicate which provider is being used

### 4. Update System Prompt

**File**: [`SystemPrompts.php`](../includes/Data/SystemPrompts.php)
- Update system prompt to handle pure AI generation when no layout provided
- Add instructions for creating layouts from scratch when no base layout is provided

### 5. Update Documentation

**File**: [`README.md`](../README.md)
- Document the `PATTERN_PROVIDER` options and testing approaches in README
- Explain the difference between Blueprint, WonderBlocks, and pure AI approaches
- Add testing instructions for all three modes

## Benefits

1. **Easy A/B Testing**: Switch between three approaches with a single constant
2. **Clean Separation**: No fallback logic mixing the approaches
3. **Performance Comparison**: Test speed differences between providers and pure AI
4. **Quality Comparison**: Compare layout relevance, professional design, and AI creativity
5. **Future Flexibility**: Easy to add new providers or make it user-configurable
6. **Pure AI Testing**: Test AI's ability to generate layouts without any scaffolding

## Configuration Options

```php
// Use WonderBlocks (intent-based, professional patterns)
const PATTERN_PROVIDER = 'wonderblocks';

// Use Blueprints (random selection from Hiive API)  
const PATTERN_PROVIDER = 'blueprints';

// Pure AI generation (no layout scaffolding)
const PATTERN_PROVIDER = '';
```

## Testing Strategy

1. **WonderBlocks Mode**: Test intent recognition and pattern relevance
2. **Blueprint Mode**: Test with existing random blueprint behavior  
3. **Pure AI Mode**: Test AI's ability to create layouts from scratch
4. **Performance**: Measure response times for all three approaches
5. **Quality**: Compare layout appropriateness, design quality, and creativity
6. **Future Providers**: Framework ready for additional layout providers

## Implementation Tasks

- [ ] Add PATTERN_PROVIDER constant to AIPageDesigner class with three options
- [ ] Modify PromptBuilder to use PATTERN_PROVIDER for provider selection
- [ ] Create get_base_layout_by_provider() method supporting wonderblocks/blueprints/empty
- [ ] Update system prompt to handle pure AI generation when no layout provided
- [ ] Document the PATTERN_PROVIDER options and testing approaches in README