package com.company.internalerp.design_system.components

import androidx.compose.animation.core.animateIntAsState
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.company.internalerp.design_system.tokens.ColorTokens
import com.company.internalerp.design_system.tokens.MotionTokens
import com.company.internalerp.design_system.tokens.RadiusTokens
import com.company.internalerp.design_system.tokens.SpacingTokens
import com.company.internalerp.design_system.tokens.TypographyTokens

@Composable
fun MetricTile(
    label: String,
    value: Int,
    accent: Color,
    modifier: Modifier = Modifier
) {
    val animated by animateIntAsState(
        targetValue = value,
        animationSpec = tween(durationMillis = MotionTokens.MetricCount),
        label = "metricTileCounter"
    )
    AppCard(modifier = modifier, containerColor = ColorTokens.SurfaceCard) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(
                modifier = Modifier
                    .size(8.dp)
                    .clip(RoundedCornerShape(RadiusTokens.Pill))
                    .background(accent)
            )
            Spacer(modifier = Modifier.width(SpacingTokens.Sm))
            Text(label, color = ColorTokens.TextSecondary, fontSize = 12.sp)
        }
        Spacer(modifier = Modifier.height(SpacingTokens.Sm))
        Text(
            text = animated.toString(),
            color = ColorTokens.TextPrimary,
            fontWeight = TypographyTokens.NumberWeight,
            fontSize = 24.sp
        )
    }
}
