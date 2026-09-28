package com.company.internalerp.design_system.components

import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import com.company.internalerp.design_system.tokens.ColorTokens
import com.company.internalerp.design_system.tokens.TypographyTokens

@Composable
fun GlassPanel(
    title: String,
    modifier: Modifier = Modifier,
    content: @Composable ColumnScope.() -> Unit
) {
    AppCard(modifier = modifier) {
        Text(
            text = title,
            color = ColorTokens.TextPrimary,
            fontWeight = TypographyTokens.LabelWeight
        )
        HorizontalDivider(color = ColorTokens.DividerSoft)
        content()
    }
}
