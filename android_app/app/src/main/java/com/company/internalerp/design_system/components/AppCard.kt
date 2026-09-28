package com.company.internalerp.design_system.components

import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import com.company.internalerp.design_system.tokens.ColorTokens
import com.company.internalerp.design_system.tokens.RadiusTokens
import com.company.internalerp.design_system.tokens.ShadowTokens
import com.company.internalerp.design_system.tokens.SpacingTokens

@Composable
fun AppCard(
    modifier: Modifier = Modifier,
    containerColor: Color = ColorTokens.SurfaceGlass,
    content: @Composable ColumnScope.() -> Unit
) {
    Card(
        modifier = modifier
            .fillMaxWidth()
            .border(1.dp, ColorTokens.BorderSoft, RoundedCornerShape(RadiusTokens.Panel)),
        shape = RoundedCornerShape(RadiusTokens.Panel),
        colors = CardDefaults.cardColors(containerColor = containerColor),
        elevation = CardDefaults.cardElevation(defaultElevation = ShadowTokens.PanelElevation)
    ) {
        Column(
            modifier = Modifier.padding(SpacingTokens.Md),
            content = content
        )
    }
}
