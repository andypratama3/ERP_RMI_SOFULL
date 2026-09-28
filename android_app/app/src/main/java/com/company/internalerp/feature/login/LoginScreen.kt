package com.company.internalerp.feature.login

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Button
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import com.company.internalerp.data.repository_impl.MobileRepository
import kotlinx.coroutines.launch

@Composable
fun LoginScreen(
    repository: MobileRepository,
    onLoginSuccess: () -> Unit
) {
    var username by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var loading by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()

    Column(
        modifier = Modifier.padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp)
    ) {
        Text("Login Mobile ERP")
        OutlinedTextField(
            value = username,
            onValueChange = { username = it },
            label = { Text("Username") },
            modifier = Modifier.fillMaxWidth().semantics { contentDescription = "login_username" }
        )
        OutlinedTextField(
            value = password,
            onValueChange = { password = it },
            label = { Text("Password") },
            modifier = Modifier.fillMaxWidth().semantics { contentDescription = "login_password" }
        )
        Button(
            onClick = {
                loading = true
                error = null
                scope.launch {
                    val result = repository.login(
                        username = username.trim(),
                        password = password,
                        deviceId = "android-internal-device"
                    )
                    loading = false
                    result.onSuccess { onLoginSuccess() }
                        .onFailure { error = it.message ?: "Login gagal." }
                }
            },
            enabled = !loading && username.isNotBlank() && password.isNotBlank(),
            modifier = Modifier.fillMaxWidth().semantics { contentDescription = "login_submit" }
        ) {
            Text(if (loading) "Signing in..." else "Sign In")
        }
        if (error != null) {
            Text(error ?: "", modifier = Modifier.padding(top = 8.dp))
        }
    }
}
