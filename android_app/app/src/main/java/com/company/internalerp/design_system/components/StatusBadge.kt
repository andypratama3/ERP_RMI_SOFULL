package com.company.internalerp.design_system.components

import androidx.compose.foundation.border
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.company.internalerp.design_system.tokens.ColorTokens
import com.company.internalerp.design_system.tokens.RadiusTokens

@Composable
fun StatusBadge(status: String) {
    val color = when (status.uppercase()) {
        "OK" -> ColorTokens.NeonCyan
        "WARN" -> ColorTokens.Warn
        "FAIL" -> ColorTokens.Error
        else -> ColorTokens.NeonBlue
    }
    Surface(
        shape = RoundedCornerShape(RadiusTokens.Pill),
        color = color.copy(alpha = 0.2f),
        modifier = Modifier.border(1.dp, color.copy(alpha = 0.6f), RoundedCornerShape(RadiusTokens.Pill))
    ) {
        Text(
            text = status.uppercase(),
            color = color,
            fontSize = 12.sp,
            fontWeight = FontWeight.SemiBold,
            modifier = Modifier.padding(horizontal = 10.dp, vertical = 4.dp)
        )
    }
}
